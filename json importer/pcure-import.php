<?php
/**
 * PatientsCure JSON content importer.
 *
 * Run from the WordPress root (next to wp-load.php):
 *
 *   php pcure-import.php import-data/amlapitta.json
 *   php pcure-import.php import-data/amlapitta.json --dry-run
 *   php pcure-import.php --schema [disease|remedy|ingredient]
 *
 * Options:
 *   --dry-run        Validate and report only. Writes NOTHING to WordPress.
 *   --strict         Treat any unresolved relationship / taxonomy term as a fatal error.
 *   --create-terms   Allow creating taxonomy terms that do not exist yet.
 *   --user=<id|login> Run as this WordPress user (sets post author on new posts).
 *   --ascii          Use [OK]/[!]/[X] instead of unicode symbols (older Windows consoles).
 *   --schema[=type]  Print the ACF structure the importer reads from your site, then exit.
 *
 * Design rules:
 *  - The ACF structure is read AT RUNTIME from the field definitions that
 *    patientscure-setup.php registers. Nothing about field names, types,
 *    groups, repeaters or relationships is hardcoded here.
 *  - Additive and safe: creates or updates exactly ONE post per run, matched by
 *    (post type + slug). It never deletes posts, terms, meta or files, and it
 *    never touches ACF/CPT/taxonomy registration.
 *  - Everything is validated BEFORE anything is written.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "pcure-import.php can only be run from the command line.\n" );
}
if ( PHP_VERSION_ID < 70200 ) {
	echo "ERROR:\nPHP 7.2 or newer is required (running " . PHP_VERSION . ").\n";
	exit( 1 );
}

define( 'PCIMP_TYPES', array( 'disease', 'remedy', 'ingredient' ) );
define( 'PCIMP_RESERVED_KEYS', array( 'type', 'title', 'slug', 'status', 'content', 'seo', 'options' ) );
define( 'PCIMP_STATUSES', array( 'publish', 'draft', 'pending', 'private' ) );
define( 'PCIMP_FIELD_TYPES', array( 'text', 'textarea', 'wysiwyg', 'true_false', 'select', 'group', 'repeater', 'relationship' ) );

$GLOBALS['pcimp_ascii'] = false;

/* ======================================================================
 * Output helpers
 * ==================================================================== */

function pcimp_out( $s = '' ) {
	echo $s . "\n";
}

function pcimp_sym( $name ) {
	$ascii = ! empty( $GLOBALS['pcimp_ascii'] );
	switch ( $name ) {
		case 'ok':
			return $ascii ? '[OK]' : "\u{2713}";
		case 'warn':
			return $ascii ? '[!]' : "\u{26A0}";
		case 'bad':
			return $ascii ? '[X]' : "\u{2717}";
		case 'new':
			return $ascii ? '[+]' : '+';
	}
	return '';
}

function pcimp_fail( array $messages, $code = 1 ) {
	foreach ( $messages as $m ) {
		pcimp_out( 'ERROR:' );
		pcimp_out( $m );
		pcimp_out();
	}
	exit( $code );
}

function pcimp_err( array &$plan, $msg ) {
	$plan['errors'][] = $msg;
}

function pcimp_warn( array &$plan, $msg ) {
	$plan['warnings'][] = $msg;
}

function pcimp_usage() {
	return implode(
		"\n",
		array(
			'Usage:',
			'  php pcure-import.php <file.json> [--dry-run] [--strict] [--create-terms] [--user=<id|login>] [--ascii]',
			'  php pcure-import.php --schema [disease|remedy|ingredient]',
			'',
			'Examples:',
			'  php pcure-import.php import-data/amlapitta.json --dry-run',
			'  php pcure-import.php import-data/amlapitta.json',
		)
	);
}

/* ======================================================================
 * Small utilities
 * ==================================================================== */

function pcimp_is_list( $a ) {
	if ( ! is_array( $a ) ) {
		return false;
	}
	$i = 0;
	foreach ( $a as $k => $unused ) {
		if ( $k !== $i++ ) {
			return false;
		}
	}
	return true;
}

/** True for a non-empty JSON object (associative array). */
function pcimp_is_object( $a ) {
	return is_array( $a ) && $a !== array() && ! pcimp_is_list( $a );
}

function pcimp_json_type( $v ) {
	if ( is_null( $v ) ) {
		return 'null';
	}
	if ( is_bool( $v ) ) {
		return 'boolean';
	}
	if ( is_int( $v ) || is_float( $v ) ) {
		return 'number';
	}
	if ( is_string( $v ) ) {
		return 'string';
	}
	if ( is_array( $v ) ) {
		return ( $v === array() || pcimp_is_list( $v ) ) ? 'array' : 'object';
	}
	return gettype( $v );
}

function pcimp_index_by_name( array $fields ) {
	$out = array();
	foreach ( $fields as $f ) {
		$out[ $f['name'] ] = $f;
	}
	return $out;
}

function pcimp_names( array $fields ) {
	$out = array();
	foreach ( $fields as $f ) {
		$out[] = $f['name'];
	}
	return $out;
}

function pcimp_suggest( $key, array $candidates ) {
	$aliases = array(
		'disease_categories'    => 'disease_cat',
		'disease_category'      => 'disease_cat',
		'ingredient_categories' => 'ingredient_cat',
		'ingredient_category'   => 'ingredient_cat',
		'doshas'                => 'dosha',
	);
	if ( isset( $aliases[ $key ] ) && in_array( $aliases[ $key ], $candidates, true ) ) {
		return $aliases[ $key ];
	}
	if ( strlen( $key ) > 200 ) {
		return null;
	}
	$best = null;
	$bd   = 4;
	foreach ( $candidates as $c ) {
		$d = levenshtein( $key, $c );
		if ( $d < $bd ) {
			$bd   = $d;
			$best = $c;
		}
	}
	return $best;
}

function pcimp_ids( $v ) {
	if ( is_array( $v ) ) {
		$out = array();
		foreach ( $v as $x ) {
			if ( is_object( $x ) && isset( $x->ID ) ) {
				$out[] = (int) $x->ID;
			} elseif ( is_array( $x ) && isset( $x['ID'] ) ) {
				$out[] = (int) $x['ID'];
			} else {
				$out[] = (int) $x;
			}
		}
		return $out;
	}
	if ( null === $v || false === $v || '' === $v ) {
		return array();
	}
	return array( (int) $v );
}

/* ======================================================================
 * Arguments, JSON, WordPress bootstrap
 * ==================================================================== */

function pcimp_parse_args( array $argv ) {
	$o = array(
		'file'         => null,
		'dry_run'      => false,
		'strict'       => false,
		'create_terms' => false,
		'ascii'        => false,
		'user'         => null,
		'schema'       => false,
		'schema_type'  => null,
		'help'         => false,
		'errors'       => array(),
	);
	array_shift( $argv );
	foreach ( $argv as $arg ) {
		if ( '--dry-run' === $arg ) {
			$o['dry_run'] = true;
		} elseif ( '--strict' === $arg ) {
			$o['strict'] = true;
		} elseif ( '--create-terms' === $arg ) {
			$o['create_terms'] = true;
		} elseif ( '--ascii' === $arg ) {
			$o['ascii'] = true;
		} elseif ( '--help' === $arg || '-h' === $arg ) {
			$o['help'] = true;
		} elseif ( '--schema' === $arg ) {
			$o['schema'] = true;
		} elseif ( 0 === strpos( $arg, '--schema=' ) ) {
			$o['schema']      = true;
			$o['schema_type'] = substr( $arg, 9 );
		} elseif ( 0 === strpos( $arg, '--user=' ) ) {
			$o['user'] = substr( $arg, 7 );
		} elseif ( 0 === strpos( $arg, '--' ) ) {
			$o['errors'][] = "Unknown option: $arg";
		} elseif ( null === $o['file'] ) {
			$o['file'] = $arg;
		} else {
			$o['errors'][] = "Unexpected extra argument: $arg";
		}
	}
	if ( $o['schema'] && null !== $o['file'] && null === $o['schema_type'] ) {
		$o['schema_type'] = $o['file'];
		$o['file']        = null;
	}
	return $o;
}

function pcimp_read_json( $path ) {
	$full = $path;
	if ( ! is_file( $full ) ) {
		$alt = __DIR__ . DIRECTORY_SEPARATOR . $path;
		if ( is_file( $alt ) ) {
			$full = $alt;
		}
	}
	if ( ! is_file( $full ) ) {
		pcimp_fail( array( "JSON file not found: $path" ) );
	}
	$raw = file_get_contents( $full );
	if ( false === $raw ) {
		pcimp_fail( array( "Could not read JSON file: $path" ) );
	}
	if ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
		$raw = substr( $raw, 3 ); // Strip UTF-8 BOM added by some Windows editors.
	}
	$data = json_decode( $raw, true, 512 );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		pcimp_fail( array( "Invalid JSON in $path: " . json_last_error_msg() ) );
	}
	if ( ! is_array( $data ) || ( $data !== array() && pcimp_is_list( $data ) ) ) {
		pcimp_fail( array( "Invalid JSON structure: the file root must be a JSON object ({ ... })." ) );
	}
	return $data;
}

function pcimp_load_wordpress() {
	$load = __DIR__ . '/wp-load.php';
	if ( ! is_file( $load ) ) {
		pcimp_fail( array( 'wp-load.php was not found next to pcure-import.php. Run this script from the WordPress root (e.g. C:\\laragon\\www\\pcure).' ) );
	}
	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		$_SERVER['HTTP_HOST'] = 'localhost';
	}
	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}
	require_once $load;
}

function pcimp_check_environment() {
	$errors = array();
	foreach ( array( 'acf_get_field_groups', 'acf_get_fields', 'acf_get_field', 'update_field', 'get_field' ) as $fn ) {
		if ( ! function_exists( $fn ) ) {
			$errors[] = "ACF is not available (function $fn() is missing). Activate Advanced Custom Fields and the PatientsCure Setup plugin.";
			break;
		}
	}
	return $errors;
}

function pcimp_yoast_active() {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
}

/* ======================================================================
 * ACF schema (read from the live site — the source of truth)
 * ==================================================================== */

function pcimp_field_max( array $field ) {
	// patientscure-setup.php declares 'max_posts'. ACF's own relationship setting is 'max'.
	// Honour whichever is set so the intent in the setup file is respected.
	foreach ( array( 'max_posts', 'max' ) as $k ) {
		if ( isset( $field[ $k ] ) && '' !== $field[ $k ] && (int) $field[ $k ] > 0 ) {
			return (int) $field[ $k ];
		}
	}
	return 0; // 0 = unlimited
}

function pcimp_expand_field( array $field ) {
	if ( ! empty( $field['key'] ) ) {
		$full = acf_get_field( $field['key'] );
		if ( is_array( $full ) ) {
			$field = $full;
		}
	}
	$name = isset( $field['_name'] ) && '' !== $field['_name'] ? $field['_name'] : $field['name'];
	$node = array(
		'key'        => $field['key'],
		'name'       => $name,
		'label'      => ( isset( $field['label'] ) && '' !== $field['label'] ) ? $field['label'] : $name,
		'type'       => $field['type'],
		'choices'    => ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) ? $field['choices'] : array(),
		'post_type'  => ( isset( $field['post_type'] ) && is_array( $field['post_type'] ) ) ? array_values( $field['post_type'] ) : array(),
		'max'        => pcimp_field_max( $field ),
		'sub_fields' => array(),
	);
	if ( in_array( $field['type'], array( 'group', 'repeater' ), true ) ) {
		$subs = ! empty( $field['sub_fields'] ) ? $field['sub_fields'] : acf_get_fields( $field );
		if ( is_array( $subs ) ) {
			foreach ( $subs as $sub ) {
				$node['sub_fields'][] = pcimp_expand_field( $sub );
			}
		}
	}
	return $node;
}

function pcimp_load_schema( $cpt, array &$plan ) {
	$groups = acf_get_field_groups( array( 'post_type' => $cpt ) );
	if ( empty( $groups ) && function_exists( 'acf_get_field_group' ) ) {
		$fallback = acf_get_field_group( 'group_' . $cpt . '_fields' );
		if ( $fallback ) {
			$groups = array( $fallback );
		}
	}
	if ( empty( $groups ) ) {
		pcimp_err( $plan, "No ACF field group is registered for post type '$cpt'. Is the PatientsCure Setup plugin active?" );
		return array( 'fields' => array(), 'groups' => array() );
	}
	$fields = array();
	$keys   = array();
	foreach ( $groups as $g ) {
		$keys[] = $g['key'];
		$top    = acf_get_fields( $g['key'] );
		if ( is_array( $top ) ) {
			foreach ( $top as $f ) {
				$fields[] = pcimp_expand_field( $f );
			}
		}
	}
	return array( 'fields' => $fields, 'groups' => $keys );
}

function pcimp_empty_value( array $field ) {
	switch ( $field['type'] ) {
		case 'true_false':
			return false;
		case 'relationship':
		case 'repeater':
		case 'group':
			return array();
	}
	return '';
}

/* ======================================================================
 * Lookups
 * ==================================================================== */

function pcimp_find_posts( $slug, array $types ) {
	global $wpdb;
	$types = array_values( $types );
	if ( empty( $types ) ) {
		return array();
	}
	$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$sql = "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ($in) AND post_status NOT IN ('trash','auto-draft','inherit')";
	return $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $slug ), $types ) ) );
}

function pcimp_resolve_slug( $slug, array $types, array &$plan ) {
	$ck = $slug . '|' . implode( ',', $types );
	if ( array_key_exists( $ck, $plan['cache'] ) ) {
		return $plan['cache'][ $ck ];
	}
	$rows = pcimp_find_posts( $slug, $types );
	if ( 1 === count( $rows ) ) {
		$res = array(
			'id'     => (int) $rows[0]->ID,
			'status' => $rows[0]->post_status,
			'type'   => $rows[0]->post_type,
		);
	} elseif ( count( $rows ) > 1 ) {
		$res = array( 'ambiguous' => $rows );
	} else {
		$res = null;
	}
	$plan['cache'][ $ck ] = $res;
	return $res;
}

/* ======================================================================
 * Validation + normalisation (name-keyed, relationship slugs -> IDs)
 * ==================================================================== */

function pcimp_result( $ok, $value, array $node, $skip = false ) {
	return array( 'ok' => $ok, 'skip' => $skip, 'value' => $value, 'node' => $node );
}

function pcimp_normalize( array $field, $value, $path, array &$plan, $in_row, $label_path ) {
	$type = $field['type'];
	$lp   = ( '' === $label_path ) ? $field['label'] : $label_path . ' > ' . $field['label'];
	$node = array(
		'label'      => $field['label'],
		'name'       => $field['name'],
		'type'       => $type,
		'rows'       => null,
		'children'   => array(),
		'skipped'    => false,
		'resolved'   => 0,
		'unresolved' => 0,
	);

	if ( ! in_array( $type, PCIMP_FIELD_TYPES, true ) ) {
		pcimp_err( $plan, "Unsupported ACF field type '$type' at $path. The importer refuses to guess how to store it." );
		return pcimp_result( false, null, $node );
	}

	switch ( $type ) {

		case 'text':
		case 'textarea':
		case 'wysiwyg':
			if ( is_int( $value ) || is_float( $value ) ) {
				$value = (string) $value;
			}
			if ( ! is_string( $value ) ) {
				pcimp_err( $plan, 'Invalid structure: ' . $path . ' must be a string but the JSON provides ' . pcimp_json_type( $value ) . " (ACF type: $type)." );
				return pcimp_result( false, null, $node );
			}
			return pcimp_result( true, $value, $node );

		case 'true_false':
			if ( ! is_bool( $value ) ) {
				pcimp_err( $plan, 'Invalid structure: ' . $path . ' must be true or false but the JSON provides ' . pcimp_json_type( $value ) . '.' );
				return pcimp_result( false, null, $node );
			}
			return pcimp_result( true, $value, $node );

		case 'select':
			if ( ! is_string( $value ) ) {
				pcimp_err( $plan, 'Invalid structure: ' . $path . ' must be a string but the JSON provides ' . pcimp_json_type( $value ) . '.' );
				return pcimp_result( false, null, $node );
			}
			if ( ! empty( $field['choices'] ) ) {
				$allowed = array_map( 'strval', array_keys( $field['choices'] ) );
				if ( ! in_array( $value, $allowed, true ) ) {
					pcimp_err( $plan, 'Invalid value: ' . $path . " = '$value'. Allowed values: " . implode( ', ', $allowed ) . '.' );
					return pcimp_result( false, null, $node );
				}
			}
			return pcimp_result( true, $value, $node );

		case 'group':
			if ( array() === $value ) {
				if ( $in_row ) {
					return pcimp_result( true, array(), $node );
				}
				pcimp_warn( $plan, "$path is empty; skipped (existing data left untouched)." );
				$node['skipped'] = true;
				return pcimp_result( true, null, $node, true );
			}
			if ( ! pcimp_is_object( $value ) ) {
				pcimp_err( $plan, 'Invalid structure: ' . $path . ' must be an object with keys: ' . implode( ', ', pcimp_names( $field['sub_fields'] ) ) . ' but the JSON provides ' . pcimp_json_type( $value ) . '.' );
				return pcimp_result( false, null, $node );
			}
			$by  = pcimp_index_by_name( $field['sub_fields'] );
			$out = array();
			$ok  = true;
			foreach ( $value as $k => $v ) {
				$k = (string) $k;
				if ( '' !== $k && ( '_' === $k[0] || '$' === $k[0] ) ) {
					continue; // Comment key.
				}
				if ( ! isset( $by[ $k ] ) ) {
					pcimp_err( $plan, "Unknown field: $path.$k. Valid fields in '$path': " . implode( ', ', array_keys( $by ) ) . '.' );
					$ok = false;
					continue;
				}
				$r = pcimp_normalize( $by[ $k ], $v, $path . '.' . $k, $plan, $in_row, $lp );
				$node['children'][ $k ] = $r['node'];
				if ( ! $r['ok'] ) {
					$ok = false;
				} elseif ( ! $r['skip'] ) {
					$out[ $k ] = $r['value'];
				}
			}
			if ( $ok && array() === $out ) {
				$node['skipped'] = true;
				return pcimp_result( true, null, $node, true );
			}
			return pcimp_result( $ok, $out, $node );

		case 'repeater':
			if ( array() === $value ) {
				if ( $in_row ) {
					return pcimp_result( true, array(), $node );
				}
				pcimp_warn( $plan, "$path is an empty array; skipped (the importer never clears existing rows)." );
				$node['skipped'] = true;
				return pcimp_result( true, null, $node, true );
			}
			$sub_names = pcimp_names( $field['sub_fields'] );
			$example   = '{ "' . ( isset( $sub_names[0] ) ? $sub_names[0] : 'field' ) . '": "..." }';
			if ( ! pcimp_is_list( $value ) ) {
				pcimp_err( $plan, 'Invalid structure: ' . $path . ' must be an array of row objects, e.g. [ ' . $example . ' ] but the JSON provides ' . pcimp_json_type( $value ) . '.' );
				return pcimp_result( false, null, $node );
			}
			$by   = pcimp_index_by_name( $field['sub_fields'] );
			$rows = array();
			$ok   = true;
			foreach ( $value as $i => $row ) {
				$rp = $path . '[' . $i . ']';
				if ( ! pcimp_is_object( $row ) ) {
					$hint = is_string( $row ) ? " Repeater rows must be objects such as $example; plain strings are not converted." : '';
					pcimp_err( $plan, 'Invalid structure: ' . $rp . ' must be an object with keys: ' . implode( ', ', $sub_names ) . ' but the JSON provides ' . pcimp_json_type( $row ) . '.' . $hint );
					$ok = false;
					continue;
				}
				$clean = array();
				foreach ( $row as $k => $v ) {
					$k = (string) $k;
					if ( '' !== $k && ( '_' === $k[0] || '$' === $k[0] ) ) {
						continue;
					}
					if ( ! isset( $by[ $k ] ) ) {
						pcimp_err( $plan, "Unknown field: $rp.$k. Valid fields in '$path' rows: " . implode( ', ', $sub_names ) . '.' );
						$ok = false;
					}
				}
				// Every sub-field is written explicitly so a re-import can never leave stale cells behind.
				foreach ( $field['sub_fields'] as $sub ) {
					$sn = $sub['name'];
					if ( array_key_exists( $sn, $row ) ) {
						$r = pcimp_normalize( $sub, $row[ $sn ], $rp . '.' . $sn, $plan, true, $lp );
						if ( ! $r['ok'] ) {
							$ok = false;
						} else {
							$clean[ $sn ] = $r['value'];
						}
					} else {
						$clean[ $sn ] = pcimp_empty_value( $sub );
					}
				}
				$rows[] = $clean;
			}
			$node['rows'] = count( $rows );
			return pcimp_result( $ok, $rows, $node );

		case 'relationship':
			return pcimp_normalize_relationship( $field, $value, $path, $plan, $in_row, $lp, $node );
	}

	return pcimp_result( false, null, $node );
}

function pcimp_normalize_relationship( array $field, $value, $path, array &$plan, $in_row, $lp, array $node ) {
	$max   = (int) $field['max'];
	$types = ! empty( $field['post_type'] ) ? $field['post_type'] : array_values( get_post_types( array( 'public' => true ) ) );
	$tlabel = implode( '/', $types );

	if ( array() === $value ) {
		if ( $in_row ) {
			return pcimp_result( true, array(), $node );
		}
		pcimp_warn( $plan, "$path is empty; skipped (the importer never clears existing relationships)." );
		$node['skipped'] = true;
		return pcimp_result( true, null, $node, true );
	}

	if ( 1 === $max ) {
		if ( ! is_string( $value ) ) {
			pcimp_err( $plan, 'Invalid structure: ' . $path . " must be a single slug string (this relationship allows 1 $tlabel) but the JSON provides " . pcimp_json_type( $value ) . '.' );
			return pcimp_result( false, null, $node );
		}
		$slugs = array( $value );
	} else {
		if ( ! pcimp_is_list( $value ) ) {
			pcimp_err( $plan, 'Invalid structure: ' . $path . ' must be an array of ' . $tlabel . ' slugs but the JSON provides ' . pcimp_json_type( $value ) . '.' );
			return pcimp_result( false, null, $node );
		}
		$slugs = $value;
		if ( $max > 1 && count( $slugs ) > $max ) {
			pcimp_err( $plan, "Invalid structure: $path allows at most $max items but the JSON provides " . count( $slugs ) . '.' );
			return pcimp_result( false, null, $node );
		}
	}

	$ids   = array();
	$ok    = true;
	$seen  = array();
	foreach ( $slugs as $i => $slug ) {
		if ( ! is_string( $slug ) || '' === trim( $slug ) ) {
			pcimp_err( $plan, 'Invalid structure: ' . $path . ( 1 === $max ? '' : "[$i]" ) . ' must be a non-empty slug string.' );
			$ok = false;
			continue;
		}
		$slug = trim( $slug );
		if ( isset( $seen[ $slug ] ) ) {
			pcimp_warn( $plan, "Duplicate relationship slug ignored: $tlabel = $slug (in $path)." );
			continue;
		}
		$seen[ $slug ] = true;
		$hit           = pcimp_resolve_slug( $slug, $types, $plan );
		if ( null === $hit ) {
			$plan['unresolved'][] = array( 'kind' => 'relationship', 'target' => $tlabel, 'value' => $slug, 'path' => $path );
			$node['unresolved']++;
			continue;
		}
		if ( isset( $hit['ambiguous'] ) ) {
			$where = array();
			foreach ( $hit['ambiguous'] as $r ) {
				$where[] = $r->post_type . ' #' . $r->ID;
			}
			pcimp_err( $plan, "Relationship target is ambiguous: $tlabel = $slug (in $path) matches multiple posts: " . implode( ', ', $where ) . '.' );
			$ok = false;
			continue;
		}
		if ( 'publish' !== $hit['status'] ) {
			pcimp_warn( $plan, "Relationship target exists but is not published: {$hit['type']} = $slug (status: {$hit['status']}, in $path)." );
		}
		$ids[] = $hit['id'];
		$node['resolved']++;
	}

	// Aggregate relationship stats per field (row indexes removed) for the report.
	$agg = $lp;
	if ( ! isset( $plan['rel_stats'][ $agg ] ) ) {
		$plan['rel_stats'][ $agg ] = array( 'resolved' => 0, 'unresolved' => 0 );
	}
	$plan['rel_stats'][ $agg ]['resolved']   += $node['resolved'];
	$plan['rel_stats'][ $agg ]['unresolved'] += $node['unresolved'];

	if ( ! $ok ) {
		return pcimp_result( false, null, $node );
	}
	if ( array() === $ids && ! $in_row ) {
		pcimp_warn( $plan, "$path: none of the relationship targets could be resolved; field skipped (existing data left untouched)." );
		$node['skipped'] = true;
		return pcimp_result( true, null, $node, true );
	}
	return pcimp_result( true, $ids, $node );
}

/* ======================================================================
 * Taxonomies
 * ==================================================================== */

function pcimp_plan_terms( $tax, $value, $create, array &$plan ) {
	if ( array() === $value ) {
		pcimp_warn( $plan, "$tax is empty; skipped (the importer never clears existing terms)." );
		return;
	}
	if ( ! pcimp_is_list( $value ) ) {
		pcimp_err( $plan, "Invalid structure: $tax must be an array of term names, e.g. [ \"Pitta\" ] but the JSON provides " . pcimp_json_type( $value ) . '.' );
		return;
	}
	$entry = array( 'ids' => array(), 'names' => array(), 'create' => array() );
	foreach ( $value as $i => $name ) {
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			pcimp_err( $plan, "Invalid structure: {$tax}[$i] must be a non-empty term name string." );
			continue;
		}
		$name = trim( $name );
		$hit  = term_exists( $name, $tax );
		if ( is_array( $hit ) && ! empty( $hit['term_id'] ) ) {
			$entry['ids'][]   = (int) $hit['term_id'];
			$entry['names'][] = $name;
		} elseif ( $create ) {
			$entry['create'][] = $name;
			$entry['names'][]  = $name;
		} else {
			$plan['unresolved'][] = array( 'kind' => 'term', 'target' => $tax, 'value' => $name, 'path' => $tax );
		}
	}
	if ( empty( $entry['ids'] ) && empty( $entry['create'] ) ) {
		pcimp_warn( $plan, "$tax: no terms could be resolved; taxonomy skipped (existing terms left untouched). Use --create-terms or options.create_missing_terms to allow new terms." );
		return;
	}
	$plan['terms'][ $tax ] = $entry;
}

/* ======================================================================
 * Build the full plan (pure validation — writes nothing)
 * ==================================================================== */

function pcimp_build_plan( array $data, array $opts ) {
	$plan = array(
		'errors'     => array(),
		'warnings'   => array(),
		'unresolved' => array(),
		'rel_stats'  => array(),
		'cache'      => array(),
		'type'       => null,
		'cpt'        => null,
		'title'      => null,
		'slug'       => null,
		'status'     => null,
		'content'    => null,
		'existing'   => null,
		'action'     => null,
		'acf'        => array(),
		'nodes'      => array(),
		'terms'      => array(),
		'seo'        => null,
		'yoast'      => pcimp_yoast_active(),
		'untouched'  => array(),
		'groups'     => array(),
	);

	// ---- type ----
	if ( ! isset( $data['type'] ) || '' === $data['type'] ) {
		pcimp_err( $plan, "Required field missing:\ntype" );
		return $plan;
	}
	if ( ! is_string( $data['type'] ) || ! in_array( $data['type'], PCIMP_TYPES, true ) ) {
		pcimp_err( $plan, 'Invalid type: ' . ( is_string( $data['type'] ) ? "'{$data['type']}'" : pcimp_json_type( $data['type'] ) ) . '. Allowed: ' . implode( ', ', PCIMP_TYPES ) . '.' );
		return $plan;
	}
	$type = $data['type'];
	if ( ! post_type_exists( $type ) ) {
		pcimp_err( $plan, ucfirst( $type ) . ' CPT does not exist.' );
		return $plan;
	}
	$plan['type'] = $type;
	$plan['cpt']  = $type;

	// ---- title / slug ----
	foreach ( array( 'title', 'slug' ) as $req ) {
		if ( ! isset( $data[ $req ] ) || ! is_string( $data[ $req ] ) || '' === trim( $data[ $req ] ) ) {
			pcimp_err( $plan, "Required field missing (must be a non-empty string):\n$req" );
		}
	}
	if ( isset( $data['title'] ) && is_string( $data['title'] ) && '' !== trim( $data['title'] ) ) {
		$plan['title'] = trim( $data['title'] );
	}
	if ( isset( $data['slug'] ) && is_string( $data['slug'] ) && '' !== trim( $data['slug'] ) ) {
		$slug = trim( $data['slug'] );
		if ( sanitize_title( $slug ) !== $slug ) {
			pcimp_err( $plan, "Invalid slug '$slug'. Use lowercase letters, numbers and hyphens only (WordPress would turn it into '" . sanitize_title( $slug ) . "')." );
		} else {
			$plan['slug'] = $slug;
		}
	}

	// ---- status / content ----
	if ( array_key_exists( 'status', $data ) ) {
		if ( ! is_string( $data['status'] ) || ! in_array( $data['status'], PCIMP_STATUSES, true ) ) {
			pcimp_err( $plan, 'Invalid status. Allowed: ' . implode( ', ', PCIMP_STATUSES ) . '.' );
		} else {
			$plan['status'] = $data['status'];
		}
	}
	if ( array_key_exists( 'content', $data ) ) {
		if ( ! is_string( $data['content'] ) ) {
			pcimp_err( $plan, 'Invalid structure: content must be a string (HTML) but the JSON provides ' . pcimp_json_type( $data['content'] ) . '.' );
		} else {
			$plan['content'] = $data['content'];
		}
	}

	// ---- options ----
	$create_terms = ! empty( $opts['create_terms'] );
	if ( array_key_exists( 'options', $data ) ) {
		if ( ! pcimp_is_object( $data['options'] ) ) {
			pcimp_err( $plan, 'Invalid structure: options must be an object, e.g. { "create_missing_terms": true }.' );
		} else {
			foreach ( $data['options'] as $ok_key => $ok_val ) {
				if ( 'create_missing_terms' === $ok_key ) {
					if ( ! is_bool( $ok_val ) ) {
						pcimp_err( $plan, 'Invalid structure: options.create_missing_terms must be true or false.' );
					} elseif ( $ok_val ) {
						$create_terms = true;
					}
				} else {
					pcimp_err( $plan, "Unknown option: options.$ok_key. Valid options: create_missing_terms." );
				}
			}
		}
	}

	// ---- schema (live ACF definitions) ----
	$schema = pcimp_load_schema( $type, $plan );
	if ( empty( $schema['fields'] ) ) {
		return $plan;
	}
	$plan['groups'] = $schema['groups'];
	$by_name        = pcimp_index_by_name( $schema['fields'] );
	$taxes          = get_object_taxonomies( $type );

	foreach ( array_keys( $by_name ) as $n ) {
		if ( in_array( $n, PCIMP_RESERVED_KEYS, true ) || in_array( $n, $taxes, true ) ) {
			pcimp_err( $plan, "Schema conflict: the ACF field '$n' has the same name as a reserved JSON key or a taxonomy on '$type'. The importer refuses to guess which one a JSON key means." );
		}
	}

	// ---- seo ----
	if ( array_key_exists( 'seo', $data ) ) {
		$seo = $data['seo'];
		if ( ! pcimp_is_object( $seo ) ) {
			pcimp_err( $plan, 'Invalid structure: seo must be an object with optional keys: title, description.' );
		} else {
			$clean = array();
			foreach ( $seo as $sk => $sv ) {
				if ( 'title' !== $sk && 'description' !== $sk ) {
					pcimp_err( $plan, "Unknown field: seo.$sk. Valid fields: title, description." );
				} elseif ( ! is_string( $sv ) ) {
					pcimp_err( $plan, "Invalid structure: seo.$sk must be a string but the JSON provides " . pcimp_json_type( $sv ) . '.' );
				} else {
					$clean[ $sk ] = $sv;
				}
			}
			$plan['seo'] = $clean;
			if ( ! $plan['yoast'] && ! empty( $clean ) ) {
				pcimp_warn( $plan, 'Yoast SEO is not available on this site; seo.* will not be written.' );
			}
		}
	}

	// ---- everything else: ACF fields and taxonomies ----
	$candidates = array_merge( array_keys( $by_name ), $taxes, PCIMP_RESERVED_KEYS );
	foreach ( $data as $key => $value ) {
		$key = (string) $key;
		if ( in_array( $key, PCIMP_RESERVED_KEYS, true ) ) {
			continue;
		}
		if ( '' !== $key && ( '_' === $key[0] || '$' === $key[0] ) ) {
			continue; // Comment key.
		}
		if ( isset( $by_name[ $key ] ) ) {
			$r = pcimp_normalize( $by_name[ $key ], $value, $key, $plan, false, '' );
			if ( $r['ok'] && ! $r['skip'] ) {
				$plan['acf'][ $key ] = array( 'field' => $by_name[ $key ], 'value' => $r['value'] );
			}
			$plan['nodes'][ $key ] = $r['node'];
			continue;
		}
		if ( in_array( $key, $taxes, true ) ) {
			pcimp_plan_terms( $key, $value, $create_terms, $plan );
			continue;
		}
		if ( taxonomy_exists( $key ) ) {
			pcimp_err( $plan, "Taxonomy '$key' is not attached to the '$type' post type." );
			continue;
		}
		$hint = pcimp_suggest( $key, $candidates );
		pcimp_err( $plan, "Unknown field: $key." . ( $hint ? " Did you mean '$hint'?" : '' ) . " Valid ACF fields for '$type': " . implode( ', ', array_keys( $by_name ) ) . '. Valid taxonomies: ' . ( empty( $taxes ) ? '(none)' : implode( ', ', $taxes ) ) . '. Reserved keys: ' . implode( ', ', PCIMP_RESERVED_KEYS ) . '.' );
	}

	// ---- report order follows the schema ----
	$ordered = array();
	foreach ( $schema['fields'] as $f ) {
		if ( isset( $plan['nodes'][ $f['name'] ] ) ) {
			$ordered[ $f['name'] ] = $plan['nodes'][ $f['name'] ];
		} else {
			$plan['untouched'][] = $f['name'];
		}
	}
	$plan['nodes'] = $ordered;

	// ---- existing post? (match on post type + slug) ----
	if ( $plan['slug'] ) {
		$found = pcimp_find_posts( $plan['slug'], array( $type ) );
		if ( count( $found ) > 1 ) {
			$ids = array();
			foreach ( $found as $r ) {
				$ids[] = '#' . $r->ID;
			}
			pcimp_err( $plan, "Ambiguous: more than one '$type' post has the slug '{$plan['slug']}' (" . implode( ', ', $ids ) . '). Resolve this manually; the importer will not guess.' );
		} elseif ( 1 === count( $found ) ) {
			$plan['existing'] = $found[0];
			$plan['action']   = 'UPDATE';
		} else {
			$plan['action'] = 'CREATE';
			$others         = array();
			foreach ( array_diff( array_merge( PCIMP_TYPES, array( 'author', 'article', 'post', 'page' ) ), array( $type ) ) as $ot ) {
				if ( post_type_exists( $ot ) ) {
					$others[] = $ot;
				}
			}
			foreach ( pcimp_find_posts( $plan['slug'], $others ) as $r ) {
				pcimp_warn( $plan, "A different content type already uses the slug '{$plan['slug']}': {$r->post_type} #{$r->ID}. It will NOT be touched; a new '$type' post will be created." );
			}
		}
	}

	// ---- strict mode ----
	if ( ! empty( $opts['strict'] ) && ! empty( $plan['unresolved'] ) ) {
		pcimp_err( $plan, 'Strict mode: ' . count( $plan['unresolved'] ) . ' unresolved relationship/term target(s). Nothing was written.' );
	}

	return $plan;
}

/* ======================================================================
 * ACF write helpers
 * ==================================================================== */

/** Convert a name-keyed normalised value into ACF's key-keyed backend format. */
function pcimp_to_acf( array $field, $value ) {
	if ( 'group' === $field['type'] && is_array( $value ) ) {
		$out = array();
		foreach ( $field['sub_fields'] as $sub ) {
			if ( array_key_exists( $sub['name'], $value ) ) {
				$out[ $sub['key'] ] = pcimp_to_acf( $sub, $value[ $sub['name'] ] );
			}
		}
		return $out;
	}
	if ( 'repeater' === $field['type'] && is_array( $value ) ) {
		$rows = array();
		foreach ( $value as $row ) {
			$r = array();
			foreach ( $field['sub_fields'] as $sub ) {
				if ( array_key_exists( $sub['name'], $row ) ) {
					$r[ $sub['key'] ] = pcimp_to_acf( $sub, $row[ $sub['name'] ] );
				}
			}
			$rows[] = $r;
		}
		return $rows;
	}
	return $value;
}

function pcimp_write_field( array $field, $acf_value, $post_id, array &$notes ) {
	$key = $field['key'];
	// ACF field KEYS are always used as the selector (never names): saving by name on a
	// brand-new post makes ACF store groups/repeaters without their field references.
	if ( function_exists( 'acf_is_field_key' ) && ! acf_is_field_key( $key ) ) {
		$raw = acf_get_field( $key );
		if ( is_array( $raw ) && function_exists( 'acf_update_value' ) ) {
			$notes[] = "Field key '$key' does not start with 'field_'; written via acf_update_value() instead of update_field().";
			return acf_update_value( $acf_value, $post_id, $raw );
		}
	}
	return update_field( $key, $acf_value, $post_id );
}

/* ======================================================================
 * Verification (read back what WordPress actually stored)
 * ==================================================================== */

function pcimp_pick( $arr, array $sub ) {
	if ( is_array( $arr ) ) {
		if ( array_key_exists( $sub['key'], $arr ) ) {
			return $arr[ $sub['key'] ];
		}
		if ( array_key_exists( $sub['name'], $arr ) ) {
			return $arr[ $sub['name'] ];
		}
	}
	return null;
}

function pcimp_verify_value( array $field, $expected, $actual, $path, array &$problems ) {
	switch ( $field['type'] ) {
		case 'true_false':
			if ( (bool) $actual !== (bool) $expected ) {
				$problems[] = "$path: expected " . ( $expected ? 'true' : 'false' ) . ' but WordPress stored something else.';
			}
			return;
		case 'relationship':
			if ( pcimp_ids( $actual ) !== $expected ) {
				$problems[] = "$path: expected relationship IDs [" . implode( ',', $expected ) . '] but read back [' . implode( ',', pcimp_ids( $actual ) ) . '].';
			}
			return;
		case 'group':
			if ( ! is_array( $actual ) ) {
				$problems[] = "$path: expected a group (array) but read back " . pcimp_json_type( $actual ) . '.';
				return;
			}
			foreach ( $field['sub_fields'] as $sub ) {
				if ( array_key_exists( $sub['name'], $expected ) ) {
					pcimp_verify_value( $sub, $expected[ $sub['name'] ], pcimp_pick( $actual, $sub ), $path . '.' . $sub['name'], $problems );
				}
			}
			return;
		case 'repeater':
			if ( ! is_array( $actual ) || count( $actual ) !== count( $expected ) ) {
				$problems[] = "$path: expected " . count( $expected ) . ' rows but read back ' . ( is_array( $actual ) ? count( $actual ) : 0 ) . '.';
				return;
			}
			foreach ( array_values( $expected ) as $i => $row ) {
				$arow = array_values( $actual );
				foreach ( $field['sub_fields'] as $sub ) {
					if ( array_key_exists( $sub['name'], $row ) ) {
						pcimp_verify_value( $sub, $row[ $sub['name'] ], pcimp_pick( $arow[ $i ], $sub ), $path . '[' . $i . '].' . $sub['name'], $problems );
					}
				}
			}
			return;
	}
	if ( ! is_scalar( $actual ) || (string) $actual !== (string) $expected ) {
		$problems[] = "$path: stored text does not match the JSON value.";
	}
}

/** Check the raw postmeta rows: repeater row counts and relationship IDs stored as numbers. */
function pcimp_verify_raw( array $field, $value, $post_id, $meta_name, array &$problems, &$checked ) {
	switch ( $field['type'] ) {
		case 'repeater':
			$count = (int) get_post_meta( $post_id, $meta_name, true );
			$checked++;
			if ( $count !== count( $value ) ) {
				$problems[] = "postmeta '$meta_name': row count is $count, expected " . count( $value ) . '.';
				return;
			}
			foreach ( array_values( $value ) as $i => $row ) {
				foreach ( $field['sub_fields'] as $sub ) {
					if ( in_array( $sub['type'], array( 'repeater', 'group', 'relationship' ), true ) && array_key_exists( $sub['name'], $row ) && array() !== $row[ $sub['name'] ] ) {
						pcimp_verify_raw( $sub, $row[ $sub['name'] ], $post_id, $meta_name . '_' . $i . '_' . $sub['name'], $problems, $checked );
					}
				}
			}
			return;
		case 'group':
			foreach ( $field['sub_fields'] as $sub ) {
				if ( array_key_exists( $sub['name'], $value ) && in_array( $sub['type'], array( 'repeater', 'group', 'relationship' ), true ) ) {
					pcimp_verify_raw( $sub, $value[ $sub['name'] ], $post_id, $meta_name . '_' . $sub['name'], $problems, $checked );
				}
			}
			return;
		case 'relationship':
			$raw = maybe_unserialize( get_post_meta( $post_id, $meta_name, true ) );
			$checked++;
			$got = is_array( $raw ) ? $raw : ( '' === $raw ? array() : array( $raw ) );
			foreach ( $got as $g ) {
				if ( ! is_numeric( $g ) ) {
					$problems[] = "postmeta '$meta_name': relationship contains a non-ID value (a slug stored as text?).";
					return;
				}
			}
			if ( pcimp_ids( $got ) !== $value ) {
				$problems[] = "postmeta '$meta_name': relationship IDs [" . implode( ',', pcimp_ids( $got ) ) . '] differ from expected [' . implode( ',', $value ) . '].';
			}
			return;
	}
}

/* ======================================================================
 * Execute (only ever called when NOT --dry-run)
 * ==================================================================== */

function pcimp_execute( array $plan ) {
	$res = array(
		'errors'          => array(),
		'notes'           => array(),
		'post_id'         => null,
		'action'          => null,
		'created_terms'   => array(),
		'verify_problems' => array(),
		'verified'        => 0,
		'raw_checked'     => 0,
		'permalink'       => '',
		'status'          => '',
		'seo_written'     => array(),
		'warnings'        => array(),
	);

	$cpt  = $plan['cpt'];
	$meta = array();
	if ( null !== $plan['seo'] && $plan['yoast'] ) {
		if ( isset( $plan['seo']['title'] ) ) {
			$meta['_yoast_wpseo_title'] = $plan['seo']['title'];
		}
		if ( isset( $plan['seo']['description'] ) ) {
			$meta['_yoast_wpseo_metadesc'] = $plan['seo']['description'];
		}
	}

	// ---- 1. the post itself ----
	if ( $plan['existing'] ) {
		$post_id        = (int) $plan['existing']->ID;
		$current_status = $plan['existing']->post_status;
		$target_status  = $plan['status'];
		$args           = array( 'ID' => $post_id, 'post_title' => $plan['title'] );
		if ( null !== $plan['content'] ) {
			$args['post_content'] = $plan['content'];
		}
		if ( $meta ) {
			$args['meta_input'] = $meta;
		}
		$r = wp_update_post( wp_slash( $args ), true );
		if ( is_wp_error( $r ) || ! $r ) {
			$res['errors'][] = 'Could not update post #' . $post_id . ': ' . ( is_wp_error( $r ) ? $r->get_error_message() : 'unknown error' );
			return $res;
		}
		$res['action'] = 'UPDATED';
	} else {
		$target_status  = null !== $plan['status'] ? $plan['status'] : 'publish';
		// New published posts are created as drafts and published LAST, so nothing half-built goes live.
		$current_status = ( 'publish' === $target_status ) ? 'draft' : $target_status;
		$args           = array(
			'post_type'   => $cpt,
			'post_title'  => $plan['title'],
			'post_name'   => $plan['slug'],
			'post_status' => $current_status,
		);
		if ( null !== $plan['content'] ) {
			$args['post_content'] = $plan['content'];
		}
		if ( $meta ) {
			$args['meta_input'] = $meta;
		}
		$r = wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $r ) || ! $r ) {
			$res['errors'][] = 'Could not create the post: ' . ( is_wp_error( $r ) ? $r->get_error_message() : 'unknown error' );
			return $res;
		}
		$post_id       = (int) $r;
		$res['action'] = 'CREATED';
	}
	$res['post_id'] = $post_id;

	// ---- 2. taxonomies (set directly; wp_insert_post's tax_input needs capabilities the CLI lacks) ----
	foreach ( $plan['terms'] as $tax => $t ) {
		$ids = $t['ids'];
		foreach ( $t['create'] as $name ) {
			$ins = wp_insert_term( $name, $tax );
			if ( is_wp_error( $ins ) ) {
				$existing_id = $ins->get_error_data( 'term_exists' );
				if ( $existing_id ) {
					$ids[] = (int) $existing_id;
				} else {
					$res['errors'][] = "Could not create term '$name' in '$tax': " . $ins->get_error_message();
				}
			} else {
				$ids[]                    = (int) $ins['term_id'];
				$res['created_terms'][] = $tax . ' = ' . $name;
			}
		}
		if ( ! empty( $ids ) ) {
			$set = wp_set_object_terms( $post_id, array_map( 'intval', $ids ), $tax, false );
			if ( is_wp_error( $set ) ) {
				$res['errors'][] = "Could not assign '$tax' terms: " . $set->get_error_message();
			}
		}
	}

	// ---- 3. ACF fields (update_field with FIELD KEYS and ACF's nested array format) ----
	foreach ( $plan['acf'] as $name => $item ) {
		pcimp_write_field( $item['field'], pcimp_to_acf( $item['field'], $item['value'] ), $post_id, $res['notes'] );
	}

	// ---- 4. final status (publishes new posts last) ----
	if ( null !== $target_status && $target_status !== $current_status ) {
		$r = wp_update_post( array( 'ID' => $post_id, 'post_status' => $target_status ), true );
		if ( is_wp_error( $r ) || ! $r ) {
			$res['errors'][] = "Could not set post status '$target_status': " . ( is_wp_error( $r ) ? $r->get_error_message() : 'unknown error' ) . ' (the post remains as a ' . $current_status . ').';
		}
	}

	$res['status']    = get_post_status( $post_id );
	$res['permalink'] = get_permalink( $post_id );
	$saved_slug       = get_post_field( 'post_name', $post_id );
	if ( $saved_slug !== $plan['slug'] ) {
		$res['warnings'][] = "WordPress changed the slug from '{$plan['slug']}' to '$saved_slug'. A later import with the original slug would create a second post. Check for a conflicting URL.";
	}

	// ---- 5. verify from the database ----
	foreach ( $plan['acf'] as $name => $item ) {
		$actual = get_field( $item['field']['key'], $post_id, false );
		pcimp_verify_value( $item['field'], $item['value'], $actual, $name, $res['verify_problems'] );
		pcimp_verify_raw( $item['field'], $item['value'], $post_id, $item['field']['name'], $res['verify_problems'], $res['raw_checked'] );
		$res['verified']++;
	}
	foreach ( $plan['terms'] as $tax => $t ) {
		$assigned = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'names' ) );
		if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
			$res['verify_problems'][] = "taxonomy '$tax': no terms are assigned after import.";
		}
	}
	if ( ! empty( $meta ) ) {
		foreach ( $meta as $mk => $mv ) {
			if ( get_post_meta( $post_id, $mk, true ) !== $mv ) {
				$res['verify_problems'][] = "Yoast meta '$mk' was not stored as expected.";
			} else {
				$res['seo_written'][] = $mk;
			}
		}
	}

	return $res;
}

/* ======================================================================
 * Reporting
 * ==================================================================== */

function pcimp_print_nodes( array $nodes, $indent ) {
	$pad = str_repeat( ' ', $indent );
	foreach ( $nodes as $n ) {
		if ( 'relationship' === $n['type'] ) {
			continue; // Listed under RELATIONSHIPS.
		}
		if ( $n['skipped'] ) {
			pcimp_out( $pad . pcimp_sym( 'warn' ) . ' ' . $n['label'] . ': skipped (empty; existing data left untouched)' );
			continue;
		}
		if ( 'repeater' === $n['type'] ) {
			$c = (int) $n['rows'];
			pcimp_out( $pad . pcimp_sym( 'ok' ) . ' ' . $n['label'] . ': ' . $c . ( 1 === $c ? ' row' : ' rows' ) );
		} elseif ( 'group' === $n['type'] ) {
			pcimp_out( $pad . pcimp_sym( 'ok' ) . ' ' . $n['label'] );
			pcimp_print_nodes( $n['children'], $indent + 4 );
		} else {
			pcimp_out( $pad . pcimp_sym( 'ok' ) . ' ' . $n['label'] );
		}
	}
}

function pcimp_report( array $plan, $res, array $opts ) {
	$dry  = ( null === $res );
	$line = str_repeat( '=', 40 );
	$dash = str_repeat( '-', 40 );

	pcimp_out( $line );
	pcimp_out( 'PatientsCure Import' . ( $dry ? '  (DRY RUN - nothing is written)' : '' ) );
	pcimp_out( $line );
	pcimp_out();
	pcimp_out( 'Type:' );
	pcimp_out( $plan['type'] );
	pcimp_out();
	pcimp_out( 'Action:' );
	if ( $dry ) {
		pcimp_out( 'CREATE' === $plan['action'] ? 'WOULD CREATE' : 'WOULD UPDATE' );
	} else {
		pcimp_out( $res['action'] ? $res['action'] : 'FAILED' );
	}
	pcimp_out();
	pcimp_out( 'Post ID:' );
	if ( $dry ) {
		pcimp_out( $plan['existing'] ? (string) $plan['existing']->ID : '(assigned on creation)' );
	} else {
		pcimp_out( $res['post_id'] ? (string) $res['post_id'] : '(none)' );
	}
	pcimp_out();
	pcimp_out( 'Title:' );
	pcimp_out( $plan['title'] );
	pcimp_out();
	pcimp_out( 'Slug:' );
	pcimp_out( $plan['slug'] );
	pcimp_out();
	pcimp_out( 'Status:' );
	if ( $dry ) {
		if ( $plan['existing'] ) {
			pcimp_out( $plan['status'] ? $plan['existing']->post_status . ' -> ' . $plan['status'] : $plan['existing']->post_status . ' (unchanged)' );
		} else {
			pcimp_out( $plan['status'] ? $plan['status'] : 'publish (default for new posts)' );
		}
	} else {
		pcimp_out( $res['status'] ? $res['status'] : '(unknown)' );
	}
	pcimp_out();
	pcimp_out( 'URL:' );
	if ( $dry ) {
		pcimp_out( $plan['existing'] ? get_permalink( $plan['existing']->ID ) : '(available after creation)' );
	} else {
		pcimp_out( $res['permalink'] ? $res['permalink'] : '(none)' );
	}
	pcimp_out();

	pcimp_out( 'ACF FIELDS' );
	pcimp_out( $dash );
	pcimp_out();
	if ( empty( $plan['nodes'] ) ) {
		pcimp_out( '(no ACF fields in the JSON)' );
	} else {
		pcimp_print_nodes( $plan['nodes'], 0 );
	}
	if ( ! empty( $plan['untouched'] ) ) {
		pcimp_out();
		pcimp_out( 'Not in the JSON (left untouched): ' . implode( ', ', $plan['untouched'] ) );
	}
	pcimp_out();

	pcimp_out( 'RELATIONSHIPS' );
	pcimp_out( $dash );
	pcimp_out();
	if ( empty( $plan['rel_stats'] ) ) {
		pcimp_out( '(none in the JSON)' );
	} else {
		foreach ( $plan['rel_stats'] as $label => $s ) {
			$sym  = $s['unresolved'] ? pcimp_sym( 'warn' ) : pcimp_sym( 'ok' );
			$text = $s['resolved'] . ' resolved' . ( $s['unresolved'] ? ', ' . $s['unresolved'] . ' unresolved' : '' );
			pcimp_out( $sym . ' ' . $label . ': ' . $text );
		}
	}
	pcimp_out();

	pcimp_out( 'TAXONOMIES' );
	pcimp_out( $dash );
	pcimp_out();
	if ( empty( $plan['terms'] ) ) {
		pcimp_out( '(none set)' );
	} else {
		foreach ( $plan['terms'] as $tax => $t ) {
			foreach ( $t['names'] as $nm ) {
				if ( in_array( $nm, $t['create'], true ) ) {
					pcimp_out( pcimp_sym( 'new' ) . ' ' . $tax . ': ' . $nm . ( $dry ? ' (new term - would be created)' : ' (new term - created)' ) );
				} else {
					pcimp_out( pcimp_sym( 'ok' ) . ' ' . $tax . ': ' . $nm );
				}
			}
		}
	}
	pcimp_out();

	pcimp_out( 'SEO' );
	pcimp_out( $dash );
	pcimp_out();
	if ( null === $plan['seo'] || empty( $plan['seo'] ) ) {
		pcimp_out( '(no seo block in the JSON)' );
	} elseif ( ! $plan['yoast'] ) {
		pcimp_out( pcimp_sym( 'warn' ) . ' Yoast SEO is not available - seo.* was not written' );
	} else {
		foreach ( $plan['seo'] as $k => $v ) {
			$written = $dry ? true : in_array( 'title' === $k ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc', $res['seo_written'], true );
			pcimp_out( ( $written ? pcimp_sym( 'ok' ) : pcimp_sym( 'bad' ) ) . ' Yoast ' . $k );
		}
	}
	pcimp_out();

	// ---- warnings ----
	$rel_unres  = array();
	$term_unres = array();
	foreach ( $plan['unresolved'] as $u ) {
		if ( 'term' === $u['kind'] ) {
			$term_unres[] = $u;
		} else {
			$rel_unres[] = $u;
		}
	}
	$other = $plan['warnings'];
	if ( ! $dry ) {
		$other = array_merge( $other, $res['warnings'], $res['notes'] );
	}
	if ( ! empty( $rel_unres ) || ! empty( $term_unres ) || ! empty( $other ) ) {
		pcimp_out( 'WARNINGS' );
		pcimp_out( $dash );
		pcimp_out();
		if ( ! empty( $rel_unres ) ) {
			pcimp_out( pcimp_sym( 'warn' ) . ' ' . count( $rel_unres ) . ' relationship' . ( 1 === count( $rel_unres ) ? '' : 's' ) . ' could not be resolved (target not found):' );
			foreach ( $rel_unres as $u ) {
				pcimp_out( '   ' . $u['target'] . ': ' . $u['value'] . '   [in ' . $u['path'] . ']' );
			}
		}
		if ( ! empty( $term_unres ) ) {
			pcimp_out( pcimp_sym( 'warn' ) . ' ' . count( $term_unres ) . ' taxonomy term' . ( 1 === count( $term_unres ) ? '' : 's' ) . ' not found (term creation is not enabled):' );
			foreach ( $term_unres as $u ) {
				pcimp_out( '   ' . $u['target'] . ': ' . $u['value'] );
			}
		}
		foreach ( $other as $w ) {
			pcimp_out( pcimp_sym( 'warn' ) . ' ' . $w );
		}
		pcimp_out();
	}

	// ---- real-run results ----
	if ( ! $dry ) {
		if ( ! empty( $res['created_terms'] ) ) {
			pcimp_out( 'NEW TERMS CREATED' );
			pcimp_out( $dash );
			pcimp_out();
			foreach ( $res['created_terms'] as $t ) {
				pcimp_out( pcimp_sym( 'new' ) . ' ' . $t );
			}
			pcimp_out();
		}
		pcimp_out( 'VERIFICATION (read back from WordPress)' );
		pcimp_out( $dash );
		pcimp_out();
		if ( $res['post_id'] && empty( $res['verify_problems'] ) ) {
			pcimp_out( pcimp_sym( 'ok' ) . ' ' . $res['verified'] . ' ACF field(s) match the JSON; ' . $res['raw_checked'] . ' raw repeater/relationship meta check(s) passed.' );
		} elseif ( ! $res['post_id'] ) {
			pcimp_out( '(skipped: the post was not written)' );
		} else {
			foreach ( $res['verify_problems'] as $p ) {
				pcimp_out( pcimp_sym( 'bad' ) . ' ' . $p );
			}
		}
		pcimp_out();
		foreach ( $res['errors'] as $e ) {
			pcimp_out( 'ERROR:' );
			pcimp_out( $e );
			pcimp_out();
		}
	}

	pcimp_out( $line );
	if ( $dry ) {
		pcimp_out( 'DRY RUN COMPLETE - nothing was written' );
	} elseif ( ! empty( $res['errors'] ) || ! empty( $res['verify_problems'] ) ) {
		pcimp_out( 'IMPORT FINISHED WITH PROBLEMS' );
	} else {
		pcimp_out( 'IMPORT COMPLETE' );
	}
	pcimp_out( $line );
}

/* ======================================================================
 * --schema (read-only: shows what the importer reads from your site)
 * ==================================================================== */

function pcimp_print_fields( array $fields, $indent ) {
	foreach ( $fields as $f ) {
		$detail = '';
		if ( 'relationship' === $f['type'] ) {
			$detail = ' -> ' . ( empty( $f['post_type'] ) ? '(any post type)' : implode( '/', $f['post_type'] ) ) . ( 1 === $f['max'] ? ' (single slug string)' : ' (array of slugs)' );
		} elseif ( 'select' === $f['type'] && ! empty( $f['choices'] ) ) {
			$detail = ' [' . implode( ' | ', array_map( 'strval', array_keys( $f['choices'] ) ) ) . ']';
		}
		pcimp_out( str_repeat( ' ', $indent ) . str_pad( $f['name'], 26 - $indent ) . ' ' . str_pad( $f['type'], 13 ) . $detail . '   {' . $f['key'] . '}' );
		if ( ! empty( $f['sub_fields'] ) ) {
			pcimp_print_fields( $f['sub_fields'], $indent + 4 );
		}
	}
}

function pcimp_print_schema( $only ) {
	$types = PCIMP_TYPES;
	if ( null !== $only && '' !== $only ) {
		if ( ! in_array( $only, PCIMP_TYPES, true ) ) {
			pcimp_fail( array( "Invalid schema type '$only'. Allowed: " . implode( ', ', PCIMP_TYPES ) . '.' ) );
		}
		$types = array( $only );
	}
	foreach ( $types as $type ) {
		$dummy = array( 'errors' => array() );
		pcimp_out( str_repeat( '=', 60 ) );
		pcimp_out( $type . '  (post type: ' . $type . ( post_type_exists( $type ) ? '' : ' - NOT REGISTERED' ) . ')' );
		pcimp_out( str_repeat( '=', 60 ) );
		$schema = pcimp_load_schema( $type, $dummy );
		if ( ! empty( $dummy['errors'] ) ) {
			pcimp_out( $dummy['errors'][0] );
			pcimp_out();
			continue;
		}
		pcimp_out( 'ACF group(s): ' . implode( ', ', $schema['groups'] ) );
		$taxes = post_type_exists( $type ) ? get_object_taxonomies( $type ) : array();
		pcimp_out( 'Taxonomies:   ' . ( empty( $taxes ) ? '(none)' : implode( ', ', $taxes ) ) );
		pcimp_out( 'Reserved JSON keys: ' . implode( ', ', PCIMP_RESERVED_KEYS ) );
		pcimp_out();
		pcimp_print_fields( $schema['fields'], 0 );
		pcimp_out();
	}
}

/* ======================================================================
 * Main
 * ==================================================================== */

$pcimp_opts             = pcimp_parse_args( $argv );
$GLOBALS['pcimp_ascii'] = $pcimp_opts['ascii'];

if ( $pcimp_opts['help'] ) {
	pcimp_out( pcimp_usage() );
	exit( 0 );
}
if ( ! empty( $pcimp_opts['errors'] ) ) {
	pcimp_fail( array_merge( $pcimp_opts['errors'], array( pcimp_usage() ) ) );
}
if ( ! $pcimp_opts['schema'] && null === $pcimp_opts['file'] ) {
	pcimp_fail( array( "No JSON file given.\n" . pcimp_usage() ) );
}

$pcimp_data = null;
if ( ! $pcimp_opts['schema'] ) {
	$pcimp_data = pcimp_read_json( $pcimp_opts['file'] ); // Cheap checks first, before loading WordPress.
}

pcimp_load_wordpress();

$pcimp_env = pcimp_check_environment();
if ( ! empty( $pcimp_env ) ) {
	pcimp_fail( $pcimp_env );
}

if ( $pcimp_opts['schema'] ) {
	pcimp_print_schema( $pcimp_opts['schema_type'] );
	exit( 0 );
}

if ( null !== $pcimp_opts['user'] && '' !== $pcimp_opts['user'] ) {
	$pcimp_user = is_numeric( $pcimp_opts['user'] ) ? get_user_by( 'id', (int) $pcimp_opts['user'] ) : get_user_by( 'login', $pcimp_opts['user'] );
	if ( ! $pcimp_user ) {
		pcimp_fail( array( 'User not found: ' . $pcimp_opts['user'] ) );
	}
	if ( ! $pcimp_opts['dry_run'] ) {
		wp_set_current_user( $pcimp_user->ID );
	}
}

$pcimp_plan = pcimp_build_plan( $pcimp_data, $pcimp_opts );

if ( ! empty( $pcimp_plan['errors'] ) ) {
	foreach ( $pcimp_plan['errors'] as $pcimp_e ) {
		pcimp_out( 'ERROR:' );
		pcimp_out( $pcimp_e );
		pcimp_out();
	}
	pcimp_out( 'Validation failed (' . count( $pcimp_plan['errors'] ) . ' error' . ( 1 === count( $pcimp_plan['errors'] ) ? '' : 's' ) . '). Nothing was written.' );
	exit( 1 );
}

if ( $pcimp_opts['dry_run'] ) {
	pcimp_report( $pcimp_plan, null, $pcimp_opts );
	exit( 0 );
}

$pcimp_result = pcimp_execute( $pcimp_plan );
pcimp_report( $pcimp_plan, $pcimp_result, $pcimp_opts );
exit( ( ! empty( $pcimp_result['errors'] ) || ! empty( $pcimp_result['verify_problems'] ) ) ? 2 : 0 );
