<?php
/**
 * Chart storage and post-usage tracking.
 *
 * Charts are stored as a private custom post type: the Chart.js config JSON
 * in post_content, the caption in post_excerpt, and the chart type in meta.
 * This class also tracks which posts/pages embed each chart (transient-cached
 * content scan), filters edit.php down to the posts using a chart, and strips
 * a chart's blocks out of post content when the chart is deleted.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Charts {

	const POST_TYPE  = 'coywolf_cdv_chart';
	const BLOCK_NAME = 'coywolf/chart';
	const META_TYPE  = 'coywolf_cdv_type';

	/**
	 * Chart.js chart types the plugin accepts.
	 *
	 * @var string[]
	 */
	const ALLOWED_TYPES = array( 'bar', 'line', 'pie', 'doughnut', 'polarArea', 'radar', 'scatter', 'bubble' );

	/**
	 * Post types scanned for chart blocks.
	 *
	 * @var string[]
	 */
	const SCANNED_POST_TYPES = array( 'post', 'page', 'wp_block' );

	/**
	 * Memoized usage tally for this request.
	 *
	 * @var array|null
	 */
	private $usage_tally = null;

	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'before_delete_post', array( $this, 'cleanup_on_delete' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'maybe_filter_edit_screen' ) );
		add_filter( 'posts_where', array( $this, 'filter_posts_where' ), 10, 2 );
	}

	/**
	 * Private storage post type — no admin UI of its own (the plugin's All
	 * Charts screen is the UI) and no front-end presence.
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Charts', 'coywolf-data-visualizer' ),
					'singular_name' => __( 'Chart', 'coywolf-data-visualizer' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'excerpt' ),
			)
		);
	}

	/**
	 * Load a chart as a plain array, or null when the ID doesn't resolve.
	 *
	 * @param int $chart_id Chart post ID.
	 * @return array|null { id, title, caption, type, config, created }
	 */
	public function get_chart( $chart_id ) {
		$post = get_post( (int) $chart_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		$config = json_decode( $post->post_content, true );
		if ( ! is_array( $config ) ) {
			return null;
		}
		return array(
			'id'      => (int) $post->ID,
			'title'   => (string) $post->post_title,
			'caption' => (string) $post->post_excerpt,
			'type'    => (string) get_post_meta( $post->ID, self::META_TYPE, true ),
			'config'  => $config,
			'created' => (string) $post->post_date,
		);
	}

	/**
	 * Persist a chart. The config must already be validated/sanitized
	 * (see Coywolf_CDV_AI::sanitize_suggestion()).
	 *
	 * @param string $title   Chart title.
	 * @param string $caption Chart caption.
	 * @param array  $config  Chart.js config array.
	 * @return int|WP_Error New chart ID.
	 */
	public function save_chart( $title, $caption, array $config ) {
		$type = isset( $config['type'] ) ? (string) $config['type'] : '';
		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new WP_Error( 'coywolf_cdv_bad_type', __( 'Unsupported chart type.', 'coywolf-data-visualizer' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( $title ),
				'post_excerpt' => sanitize_textarea_field( $caption ),
				'post_content' => wp_slash( (string) wp_json_encode( $config ) ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, self::META_TYPE, $type );
		$this->flush_usage_cache();
		return (int) $post_id;
	}

	/**
	 * Update a chart's Chart.js config in place (block-editor "edit chart
	 * settings" path). Title/caption are updated when provided.
	 *
	 * @param int    $chart_id Chart post ID.
	 * @param array  $config   Sanitized Chart.js config.
	 * @param string $title    Optional new title ('' keeps current).
	 * @param string $caption  Optional new caption (null keeps current).
	 * @return true|WP_Error
	 */
	public function update_chart( $chart_id, array $config, $title = '', $caption = null ) {
		$existing = $this->get_chart( $chart_id );
		if ( ! $existing ) {
			return new WP_Error( 'coywolf_cdv_not_found', __( 'Chart not found.', 'coywolf-data-visualizer' ) );
		}
		$type = isset( $config['type'] ) ? (string) $config['type'] : '';
		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new WP_Error( 'coywolf_cdv_bad_type', __( 'Unsupported chart type.', 'coywolf-data-visualizer' ) );
		}
		$update = array(
			'ID'           => (int) $chart_id,
			'post_content' => wp_slash( (string) wp_json_encode( $config ) ),
		);
		if ( '' !== $title ) {
			$update['post_title'] = sanitize_text_field( $title );
		}
		if ( null !== $caption ) {
			$update['post_excerpt'] = sanitize_textarea_field( $caption );
		}
		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( (int) $chart_id, self::META_TYPE, $type );
		return true;
	}

	/**
	 * Delete a chart permanently. Block cleanup runs via the
	 * before_delete_post hook, so wp-cli and other deletion paths get the
	 * same treatment.
	 *
	 * @param int $chart_id Chart post ID.
	 * @return bool Whether the post was deleted.
	 */
	public function delete_chart( $chart_id ) {
		$post = get_post( (int) $chart_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		return (bool) wp_delete_post( (int) $chart_id, true );
	}

	/**
	 * before_delete_post: when one of our charts is being removed, strip its
	 * blocks from every post that embeds it, then update those posts.
	 *
	 * @param int          $post_id Post being deleted.
	 * @param WP_Post|null $post    Post object (WP 5.5+).
	 */
	public function cleanup_on_delete( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		$this->remove_chart_from_posts( (int) $post_id );
		$this->flush_usage_cache();
	}

	/**
	 * Strip every coywolf/chart block referencing $chart_id out of the posts
	 * that contain it (recursing into nested blocks) and save the result.
	 *
	 * @param int $chart_id Chart post ID.
	 * @return int Number of posts updated.
	 */
	public function remove_chart_from_posts( $chart_id ) {
		$updated = 0;
		foreach ( $this->find_posts_with_chart( $chart_id ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$blocks   = parse_blocks( $post->post_content );
			$filtered = $this->strip_chart_blocks( $blocks, $chart_id );
			if ( ! $filtered['changed'] ) {
				continue; // Nothing removed.
			}
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( serialize_blocks( $filtered['blocks'] ) ),
				),
				true
			);
			if ( ! is_wp_error( $result ) ) {
				++$updated;
			}
		}
		return $updated;
	}

	/**
	 * Recursively remove matching chart blocks from a parsed block tree.
	 *
	 * @param array $blocks   Parsed blocks.
	 * @param int   $chart_id Chart post ID to remove.
	 * @return array { blocks: array, changed: bool }
	 */
	private function strip_chart_blocks( array $blocks, $chart_id ) {
		$changed = false;
		$out     = array();
		foreach ( $blocks as $block ) {
			$kept = is_array( $block ) ? $this->strip_from_block( $block, $chart_id ) : array(
				'block'   => $block,
				'changed' => false,
			);
			if ( null === $kept['block'] ) {
				$changed = true;
				continue;
			}
			if ( $kept['changed'] ) {
				$changed = true;
			}
			$out[] = $kept['block'];
		}
		return array(
			'blocks'  => $out,
			'changed' => $changed,
		);
	}

	/**
	 * Process one parsed block: remove it when it is the target chart block,
	 * otherwise filter its inner blocks — keeping innerContent's null
	 * placeholders in sync with innerBlocks so serialize_blocks() doesn't
	 * misalign the remaining children.
	 *
	 * @param array $block    Parsed block.
	 * @param int   $chart_id Chart post ID to remove.
	 * @return array { block: array|null, changed: bool }
	 */
	private function strip_from_block( array $block, $chart_id ) {
		if ( isset( $block['blockName'] ) && self::BLOCK_NAME === $block['blockName'] ) {
			$attr_id = isset( $block['attrs']['chartId'] ) ? (int) $block['attrs']['chartId'] : 0;
			if ( $attr_id === (int) $chart_id ) {
				return array(
					'block'   => null,
					'changed' => true,
				);
			}
		}

		if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
			return array(
				'block'   => $block,
				'changed' => false,
			);
		}

		$changed    = false;
		$new_inner  = array();
		$kept_flags = array(); // Per original child slot: was it kept?
		foreach ( $block['innerBlocks'] as $inner ) {
			$kept = is_array( $inner ) ? $this->strip_from_block( $inner, $chart_id ) : array(
				'block'   => $inner,
				'changed' => false,
			);
			if ( null === $kept['block'] ) {
				$changed      = true;
				$kept_flags[] = false;
				continue;
			}
			if ( $kept['changed'] ) {
				$changed = true;
			}
			$kept_flags[] = true;
			$new_inner[]  = $kept['block'];
		}

		if ( $changed ) {
			$block['innerBlocks'] = $new_inner;
			// Each null entry in innerContent marks one child block's
			// position, in order — drop the placeholders of removed children.
			if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				$new_content = array();
				$slot        = 0;
				foreach ( $block['innerContent'] as $chunk ) {
					if ( null === $chunk ) {
						if ( ! empty( $kept_flags[ $slot ] ) ) {
							$new_content[] = null;
						}
						++$slot;
					} else {
						$new_content[] = $chunk;
					}
				}
				$block['innerContent'] = $new_content;
			}
		}

		return array(
			'block'   => $block,
			'changed' => $changed,
		);
	}

	/**
	 * IDs of posts whose content contains a coywolf/chart block referencing
	 * the given chart.
	 *
	 * @param int $chart_id Chart post ID.
	 * @return int[]
	 */
	public function find_posts_with_chart( $chart_id ) {
		global $wpdb;
		$chart_id     = (int) $chart_id;
		$marker       = '%' . $wpdb->esc_like( '<!-- wp:' . self::BLOCK_NAME ) . '%';
		$with_sib     = '%' . $wpdb->esc_like( '"chartId":' . $chart_id . ',' ) . '%';
		$closing      = '%' . $wpdb->esc_like( '"chartId":' . $chart_id . '}' ) . '%';
		$placeholders = implode( ', ', array_fill( 0, count( self::SCANNED_POST_TYPES ), '%s' ) );
		$params       = array_merge( self::SCANNED_POST_TYPES, array( $marker, $with_sib, $closing ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- targeted content scan (no core API queries post_content by substring); $placeholders is a literal "%s, %s, %s".
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ( {$placeholders} )
				AND post_status NOT IN ( 'auto-draft', 'inherit', 'trash' )
				AND post_content LIKE %s
				AND ( post_content LIKE %s OR post_content LIKE %s )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Per-post-type tally of how many posts embed each chart, cached in a
	 * transient keyed by the latest content modification time (the same
	 * pattern Coywolf Custom Blocks uses).
	 *
	 * @return array { post: { chart_id: count }, page: { chart_id: count } }
	 */
	public function usage_tally() {
		if ( null !== $this->usage_tally ) {
			return $this->usage_tally;
		}

		$last_post = get_lastpostmodified( 'GMT', 'post' );
		$last_page = get_lastpostmodified( 'GMT', 'page' );
		$cache_key = 'coywolf_cdv_usage_v1_' . md5( ( $last_post ? $last_post : '0' ) . '|' . ( $last_page ? $last_page : '0' ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->usage_tally = $cached;
			return $this->usage_tally;
		}

		$this->usage_tally = $this->load_usage_tally();
		set_transient( $cache_key, $this->usage_tally, DAY_IN_SECONDS );
		return $this->usage_tally;
	}

	/**
	 * Full scan of post/page content for chart block markers.
	 *
	 * @return array
	 */
	private function load_usage_tally() {
		global $wpdb;

		$tally = array(
			'post' => array(),
			'page' => array(),
		);

		$marker = '%' . $wpdb->esc_like( '<!-- wp:' . self::BLOCK_NAME ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate scan; result is cached in a transient.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, post_content FROM {$wpdb->posts}
				WHERE post_type IN ( 'post', 'page' )
				AND post_status NOT IN ( 'auto-draft', 'inherit', 'trash' )
				AND post_content LIKE %s",
				$marker
			)
		);
		if ( ! is_array( $rows ) ) {
			return $tally;
		}

		$pattern = '#<!--\s+wp:' . preg_quote( self::BLOCK_NAME, '#' ) . '\s+(\{.*?\})\s+/?-->#s';
		foreach ( $rows as $row ) {
			$type = (string) $row->post_type;
			if ( ! isset( $tally[ $type ] ) ) {
				continue;
			}
			if ( ! preg_match_all( $pattern, (string) $row->post_content, $matches ) ) {
				continue;
			}
			$ids = array();
			foreach ( $matches[1] as $attrs_json ) {
				$attrs = json_decode( $attrs_json, true );
				if ( is_array( $attrs ) && ! empty( $attrs['chartId'] ) ) {
					$ids[] = (int) $attrs['chartId'];
				}
			}
			// Count each chart once per post so the tally matches the
			// filtered edit.php view (posts containing the chart, not
			// occurrences of it).
			foreach ( array_unique( $ids ) as $id ) {
				if ( ! isset( $tally[ $type ][ $id ] ) ) {
					$tally[ $type ][ $id ] = 0;
				}
				++$tally[ $type ][ $id ];
			}
		}
		return $tally;
	}

	/**
	 * How many posts of a type embed the chart.
	 *
	 * @param int    $chart_id  Chart post ID.
	 * @param string $post_type 'post' or 'page'.
	 * @return int
	 */
	public function count_posts_using( $chart_id, $post_type ) {
		$tally = $this->usage_tally();
		return isset( $tally[ $post_type ][ (int) $chart_id ] ) ? (int) $tally[ $post_type ][ (int) $chart_id ] : 0;
	}

	/**
	 * Drop every cached usage tally (they're keyed by content-modified time,
	 * so this just clears the current generation eagerly after chart writes).
	 */
	public function flush_usage_cache() {
		global $wpdb;
		$this->usage_tally = null;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static prefix cleanup on the options table; no user input and no core API for wildcard transient deletion.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\_transient\_coywolf\_cdv\_usage\_v1\_%'
			OR option_name LIKE '\_transient\_timeout\_coywolf\_cdv\_usage\_v1\_%'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * pre_get_posts: when edit.php carries our coywolf_cdv_chart param, stash
	 * it on the main query so filter_posts_where() can scope the list to
	 * posts embedding that chart.
	 *
	 * @param WP_Query $query Query being prepared.
	 */
	public function maybe_filter_edit_screen( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table filter.
		$chart_id = isset( $_GET['coywolf_cdv_chart'] ) ? absint( $_GET['coywolf_cdv_chart'] ) : 0;
		if ( $chart_id > 0 && current_user_can( 'edit_posts' ) ) {
			$query->set( 'coywolf_cdv_chart', $chart_id );
		}
	}

	/**
	 * posts_where: restrict a query flagged with coywolf_cdv_chart to posts
	 * whose content contains that chart's block.
	 *
	 * @param string   $where Current WHERE clause.
	 * @param WP_Query $query Running query.
	 * @return string
	 */
	public function filter_posts_where( $where, $query ) {
		$chart_id = (int) $query->get( 'coywolf_cdv_chart' );
		if ( $chart_id <= 0 ) {
			return $where;
		}
		global $wpdb;
		$marker   = '%' . $wpdb->esc_like( '<!-- wp:' . self::BLOCK_NAME ) . '%';
		$with_sib = '%' . $wpdb->esc_like( '"chartId":' . $chart_id . ',' ) . '%';
		$closing  = '%' . $wpdb->esc_like( '"chartId":' . $chart_id . '}' ) . '%';
		$where   .= $wpdb->prepare(
			" AND {$wpdb->posts}.post_content LIKE %s AND ( {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_content LIKE %s )",
			$marker,
			$with_sib,
			$closing
		);
		return $where;
	}

	/**
	 * Lightweight chart list for the block picker.
	 *
	 * @param string $search Optional title search.
	 * @param int    $limit  Max results.
	 * @return array[] { id, title, type, caption, created }
	 */
	public function list_charts( $search = '', $limit = 100 ) {
		$args = array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => max( 1, min( 200, (int) $limit ) ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$query = new WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $post ) {
			$out[] = array(
				'id'      => (int) $post->ID,
				'title'   => (string) $post->post_title,
				'type'    => (string) get_post_meta( $post->ID, self::META_TYPE, true ),
				'caption' => (string) $post->post_excerpt,
				'created' => (string) $post->post_date,
			);
		}
		return $out;
	}
}
