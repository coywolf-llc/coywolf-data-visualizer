<?php
/**
 * Uploaded data-file parsing.
 *
 * Accepts CSV / TSV / TXT / JSON / XLSX uploads, normalizes them to a rows
 * table, and renders a size-capped CSV excerpt for the Claude prompt. Files
 * are parsed straight from the upload's temp path and never persisted.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_Parser {

	const MAX_FILE_BYTES   = 10485760; // 10 MB upload ceiling.
	const MAX_PROMPT_ROWS  = 800;      // Rows included in the prompt.
	const MAX_PROMPT_CHARS = 150000;  // Character cap on the prompt table.

	/**
	 * Extensions the Add Chart upload accepts.
	 *
	 * @var string[]
	 */
	const ALLOWED_EXTENSIONS = array( 'csv', 'tsv', 'txt', 'json', 'xlsx' );

	/**
	 * Parse an uploaded file ($_FILES entry) into a normalized table.
	 *
	 * @param array $file { name, type, tmp_name, error, size }
	 * @return array|WP_Error { rows: array[], total_rows: int, truncated: bool, csv: string }
	 */
	public function parse_upload( $file ) {
		if ( ! is_array( $file ) || ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'No data file was received.', 'coywolf-data-visualizer' ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'The file upload failed. Try a smaller file.', 'coywolf-data-visualizer' ) );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'The uploaded file could not be read.', 'coywolf-data-visualizer' ) );
		}
		if ( (int) $file['size'] > self::MAX_FILE_BYTES ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'The file is too large (10 MB maximum).', 'coywolf-data-visualizer' ) );
		}

		$name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			return new WP_Error(
				'coywolf_cdv_upload',
				__( 'Unsupported file type. Upload a CSV, TSV, JSON, or Excel (.xlsx) file.', 'coywolf-data-visualizer' )
			);
		}

		if ( 'xlsx' === $ext ) {
			$rows = $this->parse_xlsx( $file['tmp_name'] );
		} else {
			$contents = $this->read_file( $file['tmp_name'] );
			if ( is_wp_error( $contents ) ) {
				return $contents;
			}
			$rows = ( 'json' === $ext )
				? $this->parse_json( $contents )
				: $this->parse_delimited_text( $contents );
		}

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$rows = $this->tidy_rows( $rows );
		if ( count( $rows ) < 2 ) {
			return new WP_Error(
				'coywolf_cdv_upload',
				__( 'The file needs at least a header row and one data row.', 'coywolf-data-visualizer' )
			);
		}

		$total     = count( $rows );
		$truncated = false;
		if ( $total > self::MAX_PROMPT_ROWS ) {
			$rows      = array_slice( $rows, 0, self::MAX_PROMPT_ROWS );
			$truncated = true;
		}

		$csv = $this->rows_to_csv( $rows );
		if ( strlen( $csv ) > self::MAX_PROMPT_CHARS ) {
			$csv       = substr( $csv, 0, self::MAX_PROMPT_CHARS );
			$csv       = substr( $csv, 0, (int) strrpos( $csv, "\n" ) );
			$truncated = true;
		}

		return array(
			'rows'       => $rows,
			'total_rows' => $total,
			'truncated'  => $truncated,
			'csv'        => $csv,
		);
	}

	/**
	 * Read a local temp file through WP_Filesystem.
	 *
	 * @param string $path Temp file path.
	 * @return string|WP_Error
	 */
	private function read_file( $path ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$contents = $wp_filesystem ? $wp_filesystem->get_contents( $path ) : false;
		if ( false === $contents || '' === $contents ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'The uploaded file could not be read.', 'coywolf-data-visualizer' ) );
		}
		return $this->normalize_encoding( (string) $contents );
	}

	/**
	 * Strip a UTF-8 BOM and ensure valid UTF-8.
	 *
	 * @param string $text Raw file contents.
	 * @return string
	 */
	private function normalize_encoding( $text ) {
		if ( "\xEF\xBB\xBF" === substr( $text, 0, 3 ) ) {
			$text = substr( $text, 3 );
		}
		return (string) wp_check_invalid_utf8( $text, true );
	}

	/**
	 * Parse an .xlsx workbook's first sheet via the vendored SimpleXLSX.
	 *
	 * @param string $path Temp file path.
	 * @return array|WP_Error Rows.
	 */
	private function parse_xlsx( $path ) {
		if ( ! class_exists( '\Shuchkin\SimpleXLSX' ) ) {
			require_once COYWOLF_CDV_PATH . 'vendor/simplexlsx/SimpleXLSX.php';
		}
		$xlsx = \Shuchkin\SimpleXLSX::parse( $path );
		if ( ! $xlsx ) {
			return new WP_Error(
				'coywolf_cdv_upload',
				__( 'The Excel file could not be parsed. Re-save it as .xlsx or export it to CSV.', 'coywolf-data-visualizer' )
			);
		}
		$rows = $xlsx->rows();
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'The Excel file has no rows on its first sheet.', 'coywolf-data-visualizer' ) );
		}
		foreach ( $rows as $i => $row ) {
			$rows[ $i ] = array_map(
				function ( $cell ) {
					return $this->normalize_encoding( (string) $cell );
				},
				(array) $row
			);
		}
		return $rows;
	}

	/**
	 * Parse a JSON file: either an array of flat objects (keys become the
	 * header) or an array of arrays.
	 *
	 * @param string $contents File contents.
	 * @return array|WP_Error Rows.
	 */
	private function parse_json( $contents ) {
		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'The JSON file must contain a non-empty array.', 'coywolf-data-visualizer' ) );
		}
		$data = array_values( $data );

		if ( is_array( $data[0] ) && $this->is_assoc( $data[0] ) ) {
			$header = array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					foreach ( array_keys( $item ) as $key ) {
						if ( ! in_array( (string) $key, $header, true ) ) {
							$header[] = (string) $key;
						}
					}
				}
			}
			$rows = array( $header );
			foreach ( $data as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$row = array();
				foreach ( $header as $key ) {
					$value = isset( $item[ $key ] ) ? $item[ $key ] : '';
					$row[] = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );
				}
				$rows[] = $row;
			}
			return $rows;
		}

		if ( is_array( $data[0] ) ) {
			$rows = array();
			foreach ( $data as $item ) {
				if ( is_array( $item ) ) {
					$rows[] = array_map(
						function ( $cell ) {
							return is_scalar( $cell ) || null === $cell ? (string) $cell : (string) wp_json_encode( $cell );
						},
						array_values( $item )
					);
				}
			}
			return $rows;
		}

		return new WP_Error( 'coywolf_cdv_upload', __( 'The JSON file must be an array of objects or an array of arrays.', 'coywolf-data-visualizer' ) );
	}

	/**
	 * Whether an array is associative (string keys).
	 *
	 * @param array $arr Array to test.
	 * @return bool
	 */
	private function is_assoc( array $arr ) {
		foreach ( array_keys( $arr ) as $key ) {
			if ( is_string( $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse delimited text (CSV/TSV/semicolon) with quote support, after
	 * sniffing the delimiter from the first line.
	 *
	 * @param string $contents File contents.
	 * @return array|WP_Error Rows.
	 */
	private function parse_delimited_text( $contents ) {
		$first_line = strtok( $contents, "\r\n" );
		if ( false === $first_line ) {
			return new WP_Error( 'coywolf_cdv_upload', __( 'The file appears to be empty.', 'coywolf-data-visualizer' ) );
		}
		$delimiter = ',';
		$best      = substr_count( $first_line, ',' );
		foreach ( array( "\t", ';' ) as $candidate ) {
			$count = substr_count( $first_line, $candidate );
			if ( $count > $best ) {
				$best      = $count;
				$delimiter = $candidate;
			}
		}
		return $this->parse_delimited( $contents, $delimiter );
	}

	/**
	 * RFC-4180-style parser over a string: handles quoted fields, doubled
	 * quotes, and newlines inside quotes (which a naive line-split breaks).
	 *
	 * @param string $text      Delimited text.
	 * @param string $delimiter Single-character delimiter.
	 * @return array Rows.
	 */
	private function parse_delimited( $text, $delimiter ) {
		$rows      = array();
		$row       = array();
		$field     = '';
		$in_quotes = false;
		$len       = strlen( $text );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $text[ $i ];
			if ( $in_quotes ) {
				if ( '"' === $char ) {
					if ( $i + 1 < $len && '"' === $text[ $i + 1 ] ) {
						$field .= '"';
						++$i;
					} else {
						$in_quotes = false;
					}
				} else {
					$field .= $char;
				}
				continue;
			}
			if ( '"' === $char && '' === $field ) {
				$in_quotes = true;
			} elseif ( $delimiter === $char ) {
				$row[] = $field;
				$field = '';
			} elseif ( "\n" === $char || "\r" === $char ) {
				if ( "\r" === $char && $i + 1 < $len && "\n" === $text[ $i + 1 ] ) {
					++$i;
				}
				$row[] = $field;
				$field = '';
				if ( count( $row ) > 1 || '' !== $row[0] ) {
					$rows[] = $row;
				}
				$row = array();
			} else {
				$field .= $char;
			}
		}
		$row[] = $field;
		if ( count( $row ) > 1 || '' !== $row[0] ) {
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Trim cells, drop fully empty rows, and pad ragged rows to the header
	 * width.
	 *
	 * @param array $rows Raw rows.
	 * @return array
	 */
	private function tidy_rows( array $rows ) {
		$out   = array();
		$width = 0;
		foreach ( $rows as $row ) {
			$row = array_map(
				function ( $cell ) {
					return trim( (string) $cell );
				},
				(array) $row
			);
			if ( '' === implode( '', $row ) ) {
				continue;
			}
			$width = max( $width, count( $row ) );
			$out[] = $row;
		}
		foreach ( $out as $i => $row ) {
			if ( count( $row ) < $width ) {
				$out[ $i ] = array_pad( $row, $width, '' );
			}
		}
		return $out;
	}

	/**
	 * Render rows back to CSV text for the prompt.
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	private function rows_to_csv( array $rows ) {
		$lines = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				$cell = (string) $cell;
				if ( false !== strpbrk( $cell, ",\"\n\r" ) ) {
					$cell = '"' . str_replace( '"', '""', $cell ) . '"';
				}
				$cells[] = $cell;
			}
			$lines[] = implode( ',', $cells );
		}
		return implode( "\n", $lines );
	}
}
