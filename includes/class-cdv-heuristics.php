<?php
/**
 * Built-in chart suggestion engine.
 *
 * Profiles the parsed data table (date, numeric, and categorical columns)
 * and generates ranked Chart.js suggestions with captions computed from the
 * data itself — no API, no network. This is the default analyzer; Claude is
 * the optional upgrade.
 *
 * Output shape matches Coywolf_CDV_AI::analyze(): a list of
 * { title, caption, type, config } suggestions.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Heuristics {

	/**
	 * Same palette the Claude prompt prescribes, so both engines look alike.
	 *
	 * @var string[]
	 */
	const PALETTE = array( '#2563eb', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16' );

	const MAX_SUGGESTIONS = 6;
	const MAX_CATEGORIES  = 200; // Beyond this a label column is treated as text. High-cardinality columns still chart usefully as top-10 rankings.
	const MAX_PIE_SLICES  = 8;
	const MAX_SERIES      = 5;   // Datasets per chart.
	const TOP_N           = 10;  // Ranking charts.
	const MAX_POINTS      = 500; // Line/scatter point cap (evenly downsampled).
	const CLASSIFY_QUORUM = 0.85; // Share of non-empty values that must match a type.

	/**
	 * Column profiles for the current table.
	 *
	 * @var array[]
	 */
	private $columns = array();

	/**
	 * Data rows (header removed).
	 *
	 * @var array[]
	 */
	private $rows = array();

	/**
	 * Generate chart suggestions from a parsed table.
	 *
	 * @param array $rows Table rows including the header row.
	 * @return array[]|WP_Error Suggestions: { title, caption, type, config }.
	 */
	public function analyze( array $rows ) {
		if ( count( $rows ) < 2 ) {
			return new WP_Error( 'coywolf_cdv_heuristics', __( 'The file needs at least a header row and one data row.', 'coywolf-data-visualizer' ) );
		}
		$header        = array_map( 'strval', array_shift( $rows ) );
		$this->rows    = $rows;
		$this->columns = $this->profile_columns( $header, $rows );

		$dates      = $this->columns_of( 'date' );
		$numerics   = $this->columns_of( 'numeric' );
		$categories = $this->columns_of( 'category' );

		// Prefer the "most categorical" column when several qualify — fewest
		// distinct labels first, so a Status column beats the unique Ticket-ID
		// column that real-world exports usually lead with.
		usort(
			$categories,
			function ( $a, $b ) {
				return $this->columns[ $a ]['distinct'] <=> $this->columns[ $b ]['distinct'];
			}
		);

		$suggestions = array();

		// 1. Time series: date column + numeric(s).
		if ( $dates && $numerics ) {
			$date = $dates[0];
			// Long-format data (repeated dates split by a category) becomes a
			// multi-series line; otherwise one dataset per numeric column.
			if ( $categories && $this->has_duplicates( $date ) && $this->columns[ $categories[0] ]['distinct'] <= self::MAX_SERIES ) {
				$suggestions[] = $this->pivoted_line( $date, $categories[0], $numerics[0] );
			} else {
				$suggestions[] = $this->time_series_line( $date, array_slice( $numerics, 0, self::MAX_SERIES ) );
			}
		}

		// 2. Category comparison: categorical + numeric(s), aggregated by sum.
		if ( $categories && $numerics ) {
			$cat = $categories[0];
			if ( $this->columns[ $cat ]['distinct'] > self::TOP_N ) {
				$suggestions[] = $this->ranking_bar( $cat, $numerics[0] );
			} else {
				$suggestions[] = $this->category_bar( $cat, array_slice( $numerics, 0, 3 ) );
			}

			// 3. Part-of-whole: few categories, one non-negative measure.
			$first = $this->aggregate_by_category( $cat, $numerics[0] );
			if ( count( $first['labels'] ) >= 2 && count( $first['labels'] ) <= self::MAX_PIE_SLICES && min( $first['values'] ) >= 0 && array_sum( $first['values'] ) > 0 ) {
				$suggestions[] = $this->share_doughnut( $cat, $numerics[0], $first );
			}

			// 4. Multi-metric profile: few categories across 3+ comparable measures.
			if ( count( $numerics ) >= 3 && $this->columns[ $cat ]['distinct'] >= 2 && $this->columns[ $cat ]['distinct'] <= self::MAX_SERIES ) {
				$radar = $this->metrics_radar( $cat, array_slice( $numerics, 0, 6 ) );
				if ( null !== $radar ) {
					$suggestions[] = $radar;
				}
			}

			// A second, high-cardinality label column (countries behind
			// regions, products behind categories) still ranks usefully.
			foreach ( array_slice( $categories, 1 ) as $other ) {
				if ( $this->columns[ $other ]['distinct'] > self::TOP_N ) {
					$suggestions[] = $this->ranking_bar( $other, $numerics[0] );
					break;
				}
			}
		}

		// 5. Correlation: two numeric columns (skip when a date axis already
		// tells the story better).
		if ( count( $numerics ) >= 2 && ! $dates ) {
			$suggestions[] = $this->correlation_scatter( $numerics[0], $numerics[1] );
		} elseif ( count( $numerics ) >= 3 ) {
			$suggestions[] = $this->correlation_scatter( $numerics[ count( $numerics ) - 2 ], $numerics[ count( $numerics ) - 1 ] );
		}

		// 6. Frequency: categorical data with no measure — count rows. ID-like
		// columns produce no chart (all counts are 1), so walk the category
		// columns until two of them chart.
		if ( $categories && ! $numerics ) {
			$emitted = 0;
			foreach ( $categories as $cat_index ) {
				$bar = $this->frequency_bar( $cat_index );
				if ( null === $bar ) {
					continue;
				}
				$suggestions[] = $bar;
				if ( 0 === $emitted && $this->columns[ $cat_index ]['distinct'] <= self::MAX_PIE_SLICES ) {
					$suggestions[] = $this->frequency_doughnut( $cat_index );
				}
				++$emitted;
				if ( $emitted >= 2 ) {
					break;
				}
			}
		}

		$suggestions = array_values( array_filter( $suggestions ) );
		if ( empty( $suggestions ) ) {
			return new WP_Error(
				'coywolf_cdv_heuristics',
				__( 'The built-in analyzer could not find chartable columns (it needs dates, numbers, or repeated categories). Try the Claude engine, or restructure the data with a header row.', 'coywolf-data-visualizer' )
			);
		}
		return array_slice( $suggestions, 0, self::MAX_SUGGESTIONS );
	}

	/* ------------------------------------------------------------------ *
	 * Column profiling
	 * ------------------------------------------------------------------ */

	/**
	 * Classify every column as date, numeric, category, or text.
	 *
	 * @param string[] $header Column names.
	 * @param array[]  $rows   Data rows.
	 * @return array[]
	 */
	private function profile_columns( array $header, array $rows ) {
		$columns = array();
		$total   = count( $rows );
		foreach ( $header as $index => $name ) {
			$values    = array();
			$numbers   = array();
			$stamps    = array();
			$nonempty  = 0;
			$num_hits  = 0;
			$date_hits = 0;
			foreach ( $rows as $row ) {
				$value    = isset( $row[ $index ] ) ? trim( (string) $row[ $index ] ) : '';
				$values[] = $value;
				if ( '' === $value ) {
					$numbers[] = null;
					$stamps[]  = null;
					continue;
				}
				++$nonempty;
				$number = $this->to_number( $value );
				if ( null !== $number ) {
					++$num_hits;
				}
				$numbers[] = $number;
				$stamp     = $this->to_timestamp( $value );
				if ( null !== $stamp ) {
					++$date_hits;
				}
				$stamps[] = $stamp;
			}

			$distinct = count( array_unique( array_filter( $values, 'strlen' ) ) );
			$type     = 'text';
			if ( $nonempty > 0 && $date_hits >= $nonempty * self::CLASSIFY_QUORUM && $distinct > 1 ) {
				$type = 'date';
			} elseif ( $nonempty > 0 && $num_hits >= $nonempty * self::CLASSIFY_QUORUM ) {
				$type = 'numeric';
			} elseif ( $distinct >= 2 && $distinct <= self::MAX_CATEGORIES ) {
				// Unique-per-row labels (one country per row, etc.) are still
				// categories — paired with a measure they make ranking charts.
				$type = 'category';
				// …but an all-unique column whose header names it as an
				// identifier (Order ID, UUID, SKU) is row plumbing, not a
				// dimension — a "Top 10 Order ID" chart is noise.
				if ( $distinct === $nonempty && $this->looks_like_id_column( $name ) ) {
					$type = 'text';
				}
			}

			$columns[ $index ] = array(
				'name'     => '' !== $name ? $name : sprintf( /* translators: %d: column number */ __( 'Column %d', 'coywolf-data-visualizer' ), $index + 1 ),
				'type'     => $type,
				'values'   => $values,
				'numbers'  => $numbers,
				'stamps'   => $stamps,
				'distinct' => $distinct,
			);
		}
		return $columns;
	}

	/**
	 * Column indexes of a given profile type, in table order.
	 *
	 * @param string $type Profile type.
	 * @return int[]
	 */
	private function columns_of( $type ) {
		$out = array();
		foreach ( $this->columns as $index => $column ) {
			if ( $column['type'] === $type ) {
				$out[] = $index;
			}
		}
		return $out;
	}

	/**
	 * Parse a cell as a number, tolerating thousands separators, currency
	 * symbols, and a trailing percent sign.
	 *
	 * @param string $value Cell value.
	 * @return float|null
	 */
	private function to_number( $value ) {
		$clean = preg_replace( '/^[\$\x{20AC}\x{00A3}\x{00A5}]\s*/u', '', trim( $value ) );
		$clean = str_replace( ',', '', $clean );
		$clean = rtrim( $clean, '%' );
		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return null;
		}
		return (float) $clean;
	}

	/**
	 * Parse a cell as a point in time. Bare 4-digit years and "Q1 2025"
	 * quarters are handled explicitly because strtotime() misreads them.
	 *
	 * @param string $value Cell value.
	 * @return int|null Unix timestamp (or a sortable surrogate).
	 */
	private function to_timestamp( $value ) {
		$value = trim( $value );
		if ( preg_match( '/^(19|20)\d{2}$/', $value ) ) {
			return mktime( 0, 0, 0, 1, 1, (int) $value );
		}
		if ( preg_match( '/^q([1-4])[\s\-\/]*((19|20)\d{2})$/i', $value, $m ) ) {
			return mktime( 0, 0, 0, ( (int) $m[1] - 1 ) * 3 + 1, 1, (int) $m[2] );
		}
		if ( preg_match( '/^((19|20)\d{2})[\s\-\/]q([1-4])$/i', $value, $m ) ) {
			return mktime( 0, 0, 0, ( (int) $m[3] - 1 ) * 3 + 1, 1, (int) $m[1] );
		}
		// Plain numbers are measurements, not dates.
		if ( is_numeric( str_replace( array( ',', '.' ), '', $value ) ) ) {
			return null;
		}
		$stamp = strtotime( $value );
		return false === $stamp ? null : $stamp;
	}

	/**
	 * Whether a column header names an identifier ("Order ID", "ticket_id",
	 * "UUID", "SKU").
	 *
	 * @param string $name Column header.
	 * @return bool
	 */
	private function looks_like_id_column( $name ) {
		return (bool) preg_match( '/(^|[\s_\-])(id|ids|uuid|guid|key|hash|sku)$/i', trim( (string) $name ) );
	}

	/**
	 * Whether a column's non-empty values repeat.
	 *
	 * @param int $index Column index.
	 * @return bool
	 */
	private function has_duplicates( $index ) {
		$nonempty = count( array_filter( $this->columns[ $index ]['values'], 'strlen' ) );
		return $this->columns[ $index ]['distinct'] < $nonempty;
	}

	/* ------------------------------------------------------------------ *
	 * Chart builders
	 * ------------------------------------------------------------------ */

	/**
	 * Line chart of one or more numeric columns over the date column.
	 *
	 * @param int   $date_index Date column.
	 * @param int[] $numerics   Numeric columns.
	 * @return array|null
	 */
	private function time_series_line( $date_index, array $numerics ) {
		$date = $this->columns[ $date_index ];

		// Order rows by time; aggregate duplicate dates by sum.
		$buckets = array();
		foreach ( $date['stamps'] as $row => $stamp ) {
			if ( null === $stamp ) {
				continue;
			}
			$label = $date['values'][ $row ];
			if ( ! isset( $buckets[ $label ] ) ) {
				$buckets[ $label ] = array(
					'stamp' => $stamp,
					'rows'  => array(),
				);
			}
			$buckets[ $label ]['rows'][] = $row;
		}
		uasort(
			$buckets,
			function ( $a, $b ) {
				return $a['stamp'] <=> $b['stamp'];
			}
		);
		$buckets = $this->downsample( $buckets );

		// strval: PHP int-casts numeric keys (bare years), Chart.js wants label strings.
		$labels   = array_map( 'strval', array_keys( $buckets ) );
		$datasets = array();
		foreach ( $numerics as $i => $num_index ) {
			$column = $this->columns[ $num_index ];
			$data   = array();
			foreach ( $buckets as $bucket ) {
				$sum = 0.0;
				foreach ( $bucket['rows'] as $row ) {
					$sum += null !== $column['numbers'][ $row ] ? $column['numbers'][ $row ] : 0;
				}
				$data[] = $this->round( $sum );
			}
			$color      = self::PALETTE[ $i % count( self::PALETTE ) ];
			$datasets[] = array(
				'label'           => $column['name'],
				'data'            => $data,
				'borderColor'     => $color,
				'backgroundColor' => $color . '33',
				'tension'         => 0.25,
				'fill'            => 1 === count( $numerics ),
			);
		}
		if ( empty( $labels ) || empty( $datasets ) ) {
			return null;
		}

		$first   = $datasets[0]['data'];
		$caption = $this->trend_caption( $this->columns[ $numerics[0] ]['name'], $labels, $first );

		return $this->suggestion(
			/* translators: 1: measure name, 2: date column name */
			sprintf( __( '%1$s over %2$s', 'coywolf-data-visualizer' ), $this->series_names( $numerics ), $date['name'] ),
			$caption,
			'line',
			$labels,
			$datasets,
			array( 'legend' => count( $datasets ) > 1 )
		);
	}

	/**
	 * Long-format data: one line per category over the date column.
	 *
	 * @param int $date_index Date column.
	 * @param int $cat_index  Category column.
	 * @param int $num_index  Measure column.
	 * @return array|null
	 */
	private function pivoted_line( $date_index, $cat_index, $num_index ) {
		$date = $this->columns[ $date_index ];
		$cat  = $this->columns[ $cat_index ];
		$num  = $this->columns[ $num_index ];

		$stamps = array(); // label => stamp.
		$series = array(); // category => label => sum.
		foreach ( $date['stamps'] as $row => $stamp ) {
			if ( null === $stamp || '' === $cat['values'][ $row ] ) {
				continue;
			}
			$label            = $date['values'][ $row ];
			$group            = $cat['values'][ $row ];
			$stamps[ $label ] = $stamp;
			if ( ! isset( $series[ $group ][ $label ] ) ) {
				$series[ $group ][ $label ] = 0.0;
			}
			$series[ $group ][ $label ] += null !== $num['numbers'][ $row ] ? $num['numbers'][ $row ] : 0;
		}
		asort( $stamps );
		$labels = array_map( 'strval', array_keys( $stamps ) );
		if ( count( $labels ) < 2 || empty( $series ) ) {
			return null;
		}

		$datasets = array();
		$i        = 0;
		foreach ( $series as $group => $points ) {
			$color = self::PALETTE[ $i % count( self::PALETTE ) ];
			$data  = array();
			foreach ( $labels as $label ) {
				$data[] = isset( $points[ $label ] ) ? $this->round( $points[ $label ] ) : null;
			}
			$datasets[] = array(
				'label'           => (string) $group,
				'data'            => $data,
				'borderColor'     => $color,
				'backgroundColor' => $color . '33',
				'tension'         => 0.25,
			);
			++$i;
		}

		return $this->suggestion(
			/* translators: 1: measure name, 2: date column name, 3: category column name */
			sprintf( __( '%1$s over %2$s by %3$s', 'coywolf-data-visualizer' ), $num['name'], $date['name'], $cat['name'] ),
			/* translators: 1: number of series, 2: category column name, 3: measure name */
			sprintf( __( 'Compares %1$d %2$s series of %3$s across the period.', 'coywolf-data-visualizer' ), count( $datasets ), $cat['name'], $num['name'] ),
			'line',
			$labels,
			$datasets,
			array( 'legend' => true )
		);
	}

	/**
	 * Grouped bar chart of measures summed per category.
	 *
	 * @param int   $cat_index Category column.
	 * @param int[] $numerics  Measure columns.
	 * @return array|null
	 */
	private function category_bar( $cat_index, array $numerics ) {
		$cat   = $this->columns[ $cat_index ];
		$first = $this->aggregate_by_category( $cat_index, $numerics[0] );
		if ( count( $first['labels'] ) < 2 ) {
			return null;
		}

		$datasets = array();
		foreach ( $numerics as $i => $num_index ) {
			$agg        = $this->aggregate_by_category( $cat_index, $num_index, $first['labels'] );
			$datasets[] = array(
				'label'           => $this->columns[ $num_index ]['name'],
				'data'            => array_map( array( $this, 'round' ), $agg['values'] ),
				'backgroundColor' => self::PALETTE[ $i % count( self::PALETTE ) ],
			);
		}

		$caption = $this->leader_caption( $cat['name'], $first['labels'], $first['values'], $this->columns[ $numerics[0] ]['name'] );

		return $this->suggestion(
			/* translators: 1: measure name(s), 2: category column name */
			sprintf( __( '%1$s by %2$s', 'coywolf-data-visualizer' ), $this->series_names( $numerics ), $cat['name'] ),
			$caption,
			'bar',
			$first['labels'],
			$datasets,
			array( 'legend' => count( $datasets ) > 1 )
		);
	}

	/**
	 * Top-N horizontal ranking when there are many categories.
	 *
	 * @param int $cat_index Category column.
	 * @param int $num_index Measure column.
	 * @return array|null
	 */
	private function ranking_bar( $cat_index, $num_index ) {
		$cat = $this->columns[ $cat_index ];
		$num = $this->columns[ $num_index ];
		$agg = $this->aggregate_by_category( $cat_index, $num_index );
		if ( count( $agg['labels'] ) < 2 ) {
			return null;
		}
		arsort( $agg['map'] );
		$top    = array_slice( $agg['map'], 0, self::TOP_N, true );
		$labels = array_map( 'strval', array_keys( $top ) );
		$values = array_map( array( $this, 'round' ), array_values( $top ) );

		return $this->suggestion(
			/* translators: 1: how many shown, 2: category column name, 3: measure name */
			sprintf( __( 'Top %1$d %2$s by %3$s', 'coywolf-data-visualizer' ), count( $labels ), $cat['name'], $num['name'] ),
			sprintf(
				/* translators: 1: leading category, 2: formatted value, 3: total category count */
				__( '%1$s leads with %2$s; the top %4$d of %3$d total %5$s are shown.', 'coywolf-data-visualizer' ),
				$labels[0],
				$this->format_number( $values[0] ),
				count( $agg['labels'] ),
				count( $labels ),
				$cat['name']
			),
			'bar',
			$labels,
			array(
				array(
					'label'           => $num['name'],
					'data'            => $values,
					'backgroundColor' => self::PALETTE[0],
				),
			),
			array(
				'legend'    => false,
				'indexAxis' => 'y',
			)
		);
	}

	/**
	 * Doughnut of each category's share of a measure.
	 *
	 * @param int   $cat_index Category column.
	 * @param int   $num_index Measure column.
	 * @param array $agg       Pre-computed aggregate.
	 * @return array
	 */
	private function share_doughnut( $cat_index, $num_index, array $agg ) {
		$total = array_sum( $agg['values'] );
		$max_i = array_search( max( $agg['values'] ), $agg['values'], true );
		$share = $total > 0 ? round( 100 * $agg['values'][ $max_i ] / $total ) : 0;

		return $this->suggestion(
			/* translators: 1: measure name, 2: category column name */
			sprintf( __( 'Share of %1$s by %2$s', 'coywolf-data-visualizer' ), $this->columns[ $num_index ]['name'], $this->columns[ $cat_index ]['name'] ),
			sprintf(
				/* translators: 1: leading category, 2: its percentage share */
				__( '%1$s accounts for the largest share at %2$d%% of the total.', 'coywolf-data-visualizer' ),
				$agg['labels'][ $max_i ],
				$share
			),
			'doughnut',
			$agg['labels'],
			array(
				array(
					'label'           => $this->columns[ $num_index ]['name'],
					'data'            => array_map( array( $this, 'round' ), $agg['values'] ),
					'backgroundColor' => array_slice( array_merge( self::PALETTE, self::PALETTE ), 0, count( $agg['labels'] ) ),
				),
			),
			array( 'legend' => true )
		);
	}

	/**
	 * Radar comparing categories across several measures — only when the
	 * measures share a comparable scale (otherwise the shape is meaningless).
	 *
	 * @param int   $cat_index Category column.
	 * @param int[] $numerics  Measure columns.
	 * @return array|null
	 */
	private function metrics_radar( $cat_index, array $numerics ) {
		$maxes = array();
		foreach ( $numerics as $num_index ) {
			$values = array_filter(
				$this->columns[ $num_index ]['numbers'],
				function ( $v ) {
					return null !== $v;
				}
			);
			if ( empty( $values ) ) {
				return null;
			}
			$maxes[] = max( array_map( 'abs', $values ) );
		}
		$peak = max( $maxes );
		$base = min( $maxes );
		if ( $base <= 0 || $peak / $base > 50 ) {
			return null;
		}

		$labels = array();
		foreach ( $numerics as $num_index ) {
			$labels[] = $this->columns[ $num_index ]['name'];
		}
		$datasets = array();
		$groups   = $this->aggregate_by_category( $cat_index, $numerics[0] );
		foreach ( $groups['labels'] as $i => $group ) {
			$data = array();
			foreach ( $numerics as $num_index ) {
				$agg    = $this->aggregate_by_category( $cat_index, $num_index, $groups['labels'] );
				$data[] = $this->round( $agg['values'][ $i ] );
			}
			$color      = self::PALETTE[ $i % count( self::PALETTE ) ];
			$datasets[] = array(
				'label'           => (string) $group,
				'data'            => $data,
				'borderColor'     => $color,
				'backgroundColor' => $color . '33',
			);
		}

		return $this->suggestion(
			/* translators: %s: category column name */
			sprintf( __( 'Metric profile by %s', 'coywolf-data-visualizer' ), $this->columns[ $cat_index ]['name'] ),
			/* translators: 1: number of groups, 2: number of metrics */
			sprintf( __( 'Compares %1$d groups across %2$d metrics on one shape.', 'coywolf-data-visualizer' ), count( $datasets ), count( $labels ) ),
			'radar',
			$labels,
			$datasets,
			array( 'legend' => true )
		);
	}

	/**
	 * Scatter of two numeric columns with a correlation-strength caption.
	 *
	 * @param int $x_index X column.
	 * @param int $y_index Y column.
	 * @return array|null
	 */
	private function correlation_scatter( $x_index, $y_index ) {
		$x_col  = $this->columns[ $x_index ];
		$y_col  = $this->columns[ $y_index ];
		$points = array();
		$xs     = array();
		$ys     = array();
		foreach ( $x_col['numbers'] as $row => $x ) {
			$y = $y_col['numbers'][ $row ];
			if ( null === $x || null === $y ) {
				continue;
			}
			$points[] = array(
				'x' => $this->round( $x ),
				'y' => $this->round( $y ),
			);
			$xs[]     = $x;
			$ys[]     = $y;
		}
		if ( count( $points ) < 3 ) {
			return null;
		}
		if ( count( $points ) > self::MAX_POINTS ) {
			$step   = (int) ceil( count( $points ) / self::MAX_POINTS );
			$points = array_values(
				array_filter(
					$points,
					function ( $i ) use ( $step ) {
						return 0 === $i % $step;
					},
					ARRAY_FILTER_USE_KEY
				)
			);
		}

		$r       = $this->pearson( $xs, $ys );
		$caption = $this->correlation_caption( $x_col['name'], $y_col['name'], $r );

		return $this->suggestion(
			/* translators: 1: y-axis column name, 2: x-axis column name */
			sprintf( __( '%1$s vs %2$s', 'coywolf-data-visualizer' ), $y_col['name'], $x_col['name'] ),
			$caption,
			'scatter',
			null,
			array(
				array(
					'label'           => $y_col['name'],
					'data'            => $points,
					'backgroundColor' => self::PALETTE[0],
				),
			),
			array(
				'legend' => false,
				'axes'   => array(
					'x' => $x_col['name'],
					'y' => $y_col['name'],
				),
			)
		);
	}

	/**
	 * Bar chart of row counts per category (no numeric column needed).
	 *
	 * @param int $cat_index Category column.
	 * @return array|null
	 */
	private function frequency_bar( $cat_index ) {
		$cat    = $this->columns[ $cat_index ];
		$counts = $this->category_counts( $cat_index );
		// Every label unique → every count is 1 → nothing worth charting.
		if ( count( $counts ) < 2 || max( $counts ) < 2 ) {
			return null;
		}
		arsort( $counts );
		$counts = array_slice( $counts, 0, self::TOP_N, true );
		$labels = array_map( 'strval', array_keys( $counts ) );
		$values = array_values( $counts );

		return $this->suggestion(
			/* translators: %s: category column name */
			sprintf( __( 'Count of rows by %s', 'coywolf-data-visualizer' ), $cat['name'] ),
			sprintf(
				/* translators: 1: leading category, 2: its row count */
				__( '%1$s appears most often, with %2$s rows.', 'coywolf-data-visualizer' ),
				$labels[0],
				$this->format_number( $values[0] )
			),
			'bar',
			$labels,
			array(
				array(
					'label'           => __( 'Count', 'coywolf-data-visualizer' ),
					'data'            => $values,
					'backgroundColor' => self::PALETTE[0],
				),
			),
			array( 'legend' => false )
		);
	}

	/**
	 * Doughnut of row counts per category.
	 *
	 * @param int $cat_index Category column.
	 * @return array|null
	 */
	private function frequency_doughnut( $cat_index ) {
		$counts = $this->category_counts( $cat_index );
		if ( count( $counts ) < 2 || max( $counts ) < 2 ) {
			return null;
		}
		$labels = array_map( 'strval', array_keys( $counts ) );
		$values = array_values( $counts );
		$total  = array_sum( $values );
		$max_i  = array_search( max( $values ), $values, true );

		return $this->suggestion(
			/* translators: %s: category column name */
			sprintf( __( 'Share of rows by %s', 'coywolf-data-visualizer' ), $this->columns[ $cat_index ]['name'] ),
			sprintf(
				/* translators: 1: leading category, 2: its percentage share */
				__( '%1$s accounts for the largest share at %2$d%% of the total.', 'coywolf-data-visualizer' ),
				$labels[ $max_i ],
				$total > 0 ? round( 100 * $values[ $max_i ] / $total ) : 0
			),
			'doughnut',
			$labels,
			array(
				array(
					'label'           => __( 'Count', 'coywolf-data-visualizer' ),
					'data'            => $values,
					'backgroundColor' => array_slice( array_merge( self::PALETTE, self::PALETTE ), 0, count( $labels ) ),
				),
			),
			array( 'legend' => true )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Shared helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Sum a measure per category value.
	 *
	 * @param int        $cat_index Category column.
	 * @param int        $num_index Measure column.
	 * @param array|null $order     Optional fixed label order.
	 * @return array { labels: string[], values: float[], map: array }
	 */
	private function aggregate_by_category( $cat_index, $num_index, $order = null ) {
		$cat = $this->columns[ $cat_index ];
		$num = $this->columns[ $num_index ];
		$map = array();
		foreach ( $cat['values'] as $row => $label ) {
			if ( '' === $label ) {
				continue;
			}
			if ( ! isset( $map[ $label ] ) ) {
				$map[ $label ] = 0.0;
			}
			$map[ $label ] += null !== $num['numbers'][ $row ] ? $num['numbers'][ $row ] : 0;
		}
		if ( is_array( $order ) ) {
			$ordered = array();
			foreach ( $order as $label ) {
				$ordered[ $label ] = isset( $map[ $label ] ) ? $map[ $label ] : 0.0;
			}
			$map = $ordered;
		}
		return array(
			'labels' => array_map( 'strval', array_keys( $map ) ),
			'values' => array_values( $map ),
			'map'    => $map,
		);
	}

	/**
	 * Row counts per category value.
	 *
	 * @param int $cat_index Category column.
	 * @return array label => count.
	 */
	private function category_counts( $cat_index ) {
		$counts = array();
		foreach ( $this->columns[ $cat_index ]['values'] as $label ) {
			if ( '' === $label ) {
				continue;
			}
			$counts[ $label ] = isset( $counts[ $label ] ) ? $counts[ $label ] + 1 : 1;
		}
		return $counts;
	}

	/**
	 * Assemble the suggestion array in the engine-shared shape.
	 *
	 * @param string     $title    Chart title.
	 * @param string     $caption  Caption.
	 * @param string     $type     Chart.js type.
	 * @param array|null $labels   Axis labels (null for scatter).
	 * @param array      $datasets Datasets.
	 * @param array      $opts     { legend: bool, indexAxis?: string, axes?: array }
	 * @return array
	 */
	private function suggestion( $title, $caption, $type, $labels, array $datasets, array $opts = array() ) {
		$options = array(
			'responsive'          => true,
			'maintainAspectRatio' => true,
			'plugins'             => array(
				'legend' => array( 'display' => ! empty( $opts['legend'] ) ),
			),
		);
		if ( ! empty( $opts['indexAxis'] ) ) {
			$options['indexAxis'] = (string) $opts['indexAxis'];
		}
		if ( ! empty( $opts['axes'] ) ) {
			$options['scales'] = array(
				'x' => array(
					'title' => array(
						'display' => true,
						'text'    => (string) $opts['axes']['x'],
					),
				),
				'y' => array(
					'title' => array(
						'display' => true,
						'text'    => (string) $opts['axes']['y'],
					),
				),
			);
		}

		$data = array( 'datasets' => $datasets );
		if ( null !== $labels ) {
			$data['labels'] = $labels;
		}

		return array(
			'title'   => $title,
			'caption' => $caption,
			'type'    => $type,
			'config'  => array(
				'type'    => $type,
				'data'    => $data,
				'options' => $options,
			),
		);
	}

	/**
	 * "Grew/fell X% from first to last" caption for a time series.
	 *
	 * @param string $measure Measure name.
	 * @param array  $labels  Time labels.
	 * @param array  $values  Series values.
	 * @return string
	 */
	private function trend_caption( $measure, array $labels, array $values ) {
		$first = (float) $values[0];
		$last  = (float) $values[ count( $values ) - 1 ];
		$from  = $labels[0];
		$to    = $labels[ count( $labels ) - 1 ];
		if ( 0.0 === $first || $first === $last ) {
			return sprintf(
				/* translators: 1: measure name, 2: start label, 3: end label, 4: latest value */
				__( '%1$s from %2$s to %3$s, ending at %4$s.', 'coywolf-data-visualizer' ),
				$measure,
				$from,
				$to,
				$this->format_number( $last )
			);
		}
		$change = round( 100 * ( $last - $first ) / abs( $first ) );
		return sprintf(
			/* translators: 1: measure name, 2: rose/fell, 3: percent change, 4: start label, 5: end label, 6: latest value */
			__( '%1$s %2$s %3$d%% from %4$s to %5$s, ending at %6$s.', 'coywolf-data-visualizer' ),
			$measure,
			$change >= 0 ? __( 'rose', 'coywolf-data-visualizer' ) : __( 'fell', 'coywolf-data-visualizer' ),
			abs( $change ),
			$from,
			$to,
			$this->format_number( $last )
		);
	}

	/**
	 * "X leads with V (P% of the total)" caption for category bars.
	 *
	 * @param string $category Category column name.
	 * @param array  $labels   Category labels.
	 * @param array  $values   Aggregated values.
	 * @param string $measure  Measure name.
	 * @return string
	 */
	private function leader_caption( $category, array $labels, array $values, $measure ) {
		$total = array_sum( $values );
		$max_i = array_search( max( $values ), $values, true );
		$share = $total > 0 ? round( 100 * $values[ $max_i ] / $total ) : 0;
		return sprintf(
			/* translators: 1: leading category, 2: measure name, 3: formatted value, 4: percentage share, 5: number of categories */
			__( '%1$s leads %2$s with %3$s (%4$d%% of the total across %5$d %6$s).', 'coywolf-data-visualizer' ),
			$labels[ $max_i ],
			$measure,
			$this->format_number( $values[ $max_i ] ),
			$share,
			count( $labels ),
			$category
		);
	}

	/**
	 * Correlation-strength caption for scatter plots.
	 *
	 * @param string     $x_name X column name.
	 * @param string     $y_name Y column name.
	 * @param float|null $r      Pearson correlation.
	 * @return string
	 */
	private function correlation_caption( $x_name, $y_name, $r ) {
		if ( null === $r ) {
			/* translators: 1: y-axis name, 2: x-axis name */
			return sprintf( __( 'Each point is one row, plotting %1$s against %2$s.', 'coywolf-data-visualizer' ), $y_name, $x_name );
		}
		$abs = abs( $r );
		if ( $abs >= 0.7 ) {
			$strength = __( 'a strong', 'coywolf-data-visualizer' );
		} elseif ( $abs >= 0.4 ) {
			$strength = __( 'a moderate', 'coywolf-data-visualizer' );
		} else {
			$strength = __( 'a weak', 'coywolf-data-visualizer' );
		}
		return sprintf(
			/* translators: 1: x-axis name, 2: y-axis name, 3: strength phrase, 4: positive/negative, 5: correlation coefficient */
			__( '%1$s and %2$s show %3$s %4$s relationship (r = %5$s).', 'coywolf-data-visualizer' ),
			$x_name,
			$y_name,
			$strength,
			$r >= 0 ? __( 'positive', 'coywolf-data-visualizer' ) : __( 'negative', 'coywolf-data-visualizer' ),
			number_format_i18n( $r, 2 )
		);
	}

	/**
	 * Pearson correlation coefficient.
	 *
	 * @param float[] $xs X values.
	 * @param float[] $ys Y values.
	 * @return float|null
	 */
	private function pearson( array $xs, array $ys ) {
		$n = count( $xs );
		if ( $n < 3 ) {
			return null;
		}
		$mean_x = array_sum( $xs ) / $n;
		$mean_y = array_sum( $ys ) / $n;
		$cov    = 0.0;
		$var_x  = 0.0;
		$var_y  = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$dx     = $xs[ $i ] - $mean_x;
			$dy     = $ys[ $i ] - $mean_y;
			$cov   += $dx * $dy;
			$var_x += $dx * $dx;
			$var_y += $dy * $dy;
		}
		if ( 0.0 === $var_x || 0.0 === $var_y ) {
			return null;
		}
		return $cov / sqrt( $var_x * $var_y );
	}

	/**
	 * Evenly downsample an ordered bucket map to MAX_POINTS.
	 *
	 * @param array $buckets label => bucket.
	 * @return array
	 */
	private function downsample( array $buckets ) {
		if ( count( $buckets ) <= self::MAX_POINTS ) {
			return $buckets;
		}
		$step = (int) ceil( count( $buckets ) / self::MAX_POINTS );
		$out  = array();
		$i    = 0;
		foreach ( $buckets as $label => $bucket ) {
			if ( 0 === $i % $step ) {
				$out[ $label ] = $bucket;
			}
			++$i;
		}
		return $out;
	}

	/**
	 * Joined measure names for titles ("Visits", "Visits & Conversions").
	 *
	 * @param int[] $numerics Numeric column indexes.
	 * @return string
	 */
	private function series_names( array $numerics ) {
		$names = array();
		foreach ( $numerics as $index ) {
			$names[] = $this->columns[ $index ]['name'];
		}
		if ( count( $names ) > 2 ) {
			$names = array_slice( $names, 0, 2 );
			/* translators: 1: first measure, 2: second measure */
			return sprintf( __( '%1$s, %2$s & more', 'coywolf-data-visualizer' ), $names[0], $names[1] );
		}
		return implode( ' & ', $names );
	}

	/**
	 * Trim float noise for config payloads.
	 *
	 * @param float $value Value.
	 * @return float|int
	 */
	private function round( $value ) {
		$rounded = round( (float) $value, 2 );
		return floor( $rounded ) === $rounded ? (int) $rounded : $rounded;
	}

	/**
	 * Human-readable number for captions.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function format_number( $value ) {
		$value = (float) $value;
		if ( abs( $value ) >= 1000000 ) {
			return number_format_i18n( $value / 1000000, 1 ) . 'M';
		}
		if ( abs( $value ) >= 10000 ) {
			return number_format_i18n( $value / 1000, 1 ) . 'K';
		}
		return number_format_i18n( $value, floor( $value ) === $value ? 0 : 2 );
	}
}
