<?php
/**
 * Minimal MaxMind DB (.mmdb) reader for GeoLite2 Country lookups.
 *
 * Supports record sizes 24, 28, and 32 bits.
 *
 * Reads the file through a handle with fseek()/fread(), in 4 KB pages with a
 * small per-instance cache, instead of loading it whole: GeoLite2-City is
 * tens of MB, and the reader is built on frontend requests. Only the metadata
 * tail (at most 128 KB, where the format puts it) is read up front.
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mmdb_Reader {

	/**
	 * Open read handle on the database file.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Size of the database file in bytes.
	 *
	 * @var int
	 */
	private $file_size = 0;

	/**
	 * Cached pages of the file, keyed by page number.
	 *
	 * @var array<int,string>
	 */
	private $pages = array();

	/**
	 * Number of nodes in the search tree.
	 *
	 * @var int
	 */
	private $node_count;

	/**
	 * Record size in bits (24, 28, or 32).
	 *
	 * @var int
	 */
	private $record_size;

	/**
	 * Bytes per search tree node.
	 *
	 * @var int
	 */
	private $node_byte_size;

	/**
	 * Size of the search tree in bytes.
	 *
	 * @var int
	 */
	private $search_tree_size;

	/**
	 * Byte offset where the data section begins.
	 *
	 * @var int
	 */
	private $data_section_start;

	/**
	 * Database IP version (4 or 6).
	 *
	 * @var int
	 */
	private $ip_version;

	/**
	 * Database type declared in the MMDB metadata.
	 *
	 * @var string
	 */
	private $database_type = '';

	const SEPARATOR_SIZE  = 16;
	const METADATA_MARKER = "\xab\xcd\xefMaxMind.com";
	const MAX_DECODE_DEPTH = 128;
	const PAGE_SIZE        = 4096;
	const MAX_PAGES        = 256;
	const METADATA_MAX     = 131072;

	/**
	 * Open and parse an MMDB file.
	 *
	 * @param string $file Absolute path to the .mmdb file.
	 * @throws \RuntimeException If the file cannot be read or is invalid.
	 */
	public function __construct( $file ) {
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			throw new \RuntimeException( 'Cannot read MMDB file: ' . esc_html( $file ) );
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- random-access reads of a binary database; WP_Filesystem has no seek API.
		$size   = false === $handle ? false : filesize( $file );
		if ( false === $handle || false === $size ) {
			if ( false !== $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			throw new \RuntimeException( 'Cannot read MMDB file: ' . esc_html( $file ) );
		}
		$this->handle    = $handle;
		$this->file_size = (int) $size;
		$this->parse_metadata();
		$this->data_section_start = $this->search_tree_size + self::SEPARATOR_SIZE;
	}

	/**
	 * Close the file handle.
	 */
	public function __destruct() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$this->handle = null;
	}

	/**
	 * Read $length bytes at $offset (bounds-checked).
	 *
	 * @param int $offset Byte offset.
	 * @param int $length Number of bytes.
	 * @return string
	 * @throws \RuntimeException If the range lies outside the file.
	 */
	private function read_bytes( $offset, $length ) {
		$this->assert_bytes_available( $offset, $length );
		if ( 0 === $length ) {
			return '';
		}
		$out  = '';
		$page = intdiv( $offset, self::PAGE_SIZE );
		$skip = $offset - $page * self::PAGE_SIZE;
		while ( strlen( $out ) < $length ) {
			$data = $this->page( $page );
			$out .= substr( $data, $skip, $length - strlen( $out ) );
			$skip = 0;
			++$page;
		}
		return $out;
	}

	/**
	 * One byte at $offset, as an integer.
	 *
	 * @param int $offset Byte offset.
	 * @return int
	 */
	private function byte( $offset ) {
		return ord( $this->read_bytes( $offset, 1 ) );
	}

	/**
	 * Page $n of the file, from the cache or the disk.
	 *
	 * @param int $n Page number.
	 * @return string
	 * @throws \RuntimeException On a read error.
	 */
	private function page( $n ) {
		if ( isset( $this->pages[ $n ] ) ) {
			return $this->pages[ $n ];
		}
		if ( count( $this->pages ) >= self::MAX_PAGES ) {
			$this->pages = array();
		}
		$start = $n * self::PAGE_SIZE;
		$want  = min( self::PAGE_SIZE, $this->file_size - $start );
		$data  = '';
		if ( $want > 0 && 0 === fseek( $this->handle, $start ) ) {
			while ( strlen( $data ) < $want ) {
				$part = fread( $this->handle, $want - strlen( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $part || '' === $part ) {
					break;
				}
				$data .= $part;
			}
		}
		if ( strlen( $data ) !== $want ) {
			throw new \RuntimeException( 'Cannot read MMDB file at offset ' . (int) $start . '.' );
		}
		$this->pages[ $n ] = $data;
		return $data;
	}

	/**
	 * Look up an IP address and return the country ISO code.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return string Two-letter country code or empty string.
	 */
	public function country( $ip ) {
		$record = $this->find( $ip );
		if ( null === $record ) {
			return '';
		}
		$result = $this->read_record( $record );
		if ( is_array( $result ) && isset( $result['country']['iso_code'] ) ) {
			return $result['country']['iso_code'];
		}
		return '';
	}

	/**
	 * Look up an IP address and return the first subdivision ISO code.
	 *
	 * Only GeoLite2-City databases carry `subdivisions`; on a Country-only
	 * database the field is absent and this returns '' (so callers degrade
	 * gracefully to country-level routing). The returned value is the bare
	 * subdivision code (e.g. 'QC', 'CA'), NOT the full ISO 3166-2 — the caller
	 * combines it with the country to form 'CC-RR'.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return string Subdivision ISO code (e.g. 'QC') or empty string.
	 */
	public function subdivision( $ip ) {
		$record = $this->find( $ip );
		if ( null === $record ) {
			return '';
		}
		$result = $this->read_record( $record );
		if ( is_array( $result )
			&& isset( $result['subdivisions'][0]['iso_code'] )
			&& is_string( $result['subdivisions'][0]['iso_code'] ) ) {
			return $result['subdivisions'][0]['iso_code'];
		}
		return '';
	}

	/**
	 * Return the database type declared in the MMDB metadata.
	 *
	 * Examples: GeoLite2-Country, GeoLite2-City, GeoIP2-Country.
	 *
	 * @return string Database type, or an empty string when absent.
	 */
	public function database_type() {
		return $this->database_type;
	}

	/**
	 * Parse metadata from the end of the file.
	 *
	 * @throws \RuntimeException If metadata marker is not found.
	 */
	private function parse_metadata() {
		// The format keeps the metadata in the last 128 KiB of the file.
		$tail_len = min( $this->file_size, self::METADATA_MAX );
		$tail     = $this->read_bytes( $this->file_size - $tail_len, $tail_len );
		$pos      = strrpos( $tail, self::METADATA_MARKER );
		if ( false === $pos ) {
			throw new \RuntimeException( 'Invalid MMDB file: metadata marker not found.' );
		}
		unset( $tail );
		$this->pages = array();
		$offset      = $this->file_size - $tail_len + $pos + strlen( self::METADATA_MARKER );
		$meta   = $this->decode( $offset );
		if ( ! is_array( $meta ) || ! isset( $meta['node_count'], $meta['record_size'], $meta['ip_version'] ) ) {
			throw new \RuntimeException( 'Invalid MMDB metadata.' );
		}
		$this->node_count       = (int) $meta['node_count'];
		$this->record_size      = (int) $meta['record_size'];
		if ( ! in_array( $this->record_size, array( 24, 28, 32 ), true ) ) {
			throw new \RuntimeException( 'Unsupported MMDB record size: ' . esc_html( $this->record_size ) );
		}
		$this->ip_version       = (int) $meta['ip_version'];
		if ( ! in_array( $this->ip_version, array( 4, 6 ), true ) ) {
			throw new \RuntimeException( 'Unsupported MMDB ip_version: ' . esc_html( $this->ip_version ) );
		}
		$this->database_type    = isset( $meta['database_type'] ) && is_string( $meta['database_type'] )
			? $meta['database_type']
			: '';
		$this->node_byte_size   = (int) ( $this->record_size * 2 / 8 );
		$this->search_tree_size = $this->node_count * $this->node_byte_size;
		if ( $this->search_tree_size + self::SEPARATOR_SIZE > $this->file_size ) {
			throw new \RuntimeException( 'MMDB file is truncated: search tree exceeds file size.' );
		}
	}

	/**
	 * Assert that enough bytes are available at the given offset.
	 *
	 * @param int $offset Current byte offset.
	 * @param int $needed Number of bytes required.
	 * @throws \RuntimeException If the file is truncated.
	 */
	private function assert_bytes_available( $offset, $needed ) {
		if ( $offset < 0 || $needed < 0 || $offset + $needed > $this->file_size ) {
			throw new \RuntimeException( 'MMDB file is truncated at offset ' . esc_html( $offset ) . ' (need ' . esc_html( $needed ) . ' bytes).' );
		}
	}

	/**
	 * Walk the binary search tree to find the record for an IP.
	 *
	 * @param string $ip IP address.
	 * @return int|null Pointer to data record, or null if not found.
	 */
	private function find( $ip ) {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $packed ) {
			return null;
		}

		$node = 0;

		// IPv4 in IPv6 database: walk the 96-bit ::ffff: prefix (all zeros).
		if ( 4 === strlen( $packed ) && 6 === $this->ip_version ) {
			for ( $i = 0; $i < 96 && $node < $this->node_count; $i++ ) {
				$node = $this->read_node( $node, 0 );
			}
		}

		$bit_count = strlen( $packed ) * 8;
		for ( $i = 0; $i < $bit_count && $node < $this->node_count; $i++ ) {
			$bit  = ( ord( $packed[ (int) ( $i / 8 ) ] ) >> ( 7 - ( $i % 8 ) ) ) & 1;
			$node = $this->read_node( $node, $bit );
		}

		return $node > $this->node_count ? $node : null;
	}

	/**
	 * Read one record (left or right) from a search tree node.
	 *
	 * @param int $node_num Node index.
	 * @param int $bit      0 for left, 1 for right.
	 * @return int Record value.
	 */
	private function read_node( $node_num, $bit ) {
		$d   = $this->read_bytes( $node_num * $this->node_byte_size, $this->node_byte_size );
		$off = 0;

		if ( 24 === $this->record_size ) {
			if ( 0 === $bit ) {
				return ( ord( $d[ $off ] ) << 16 ) | ( ord( $d[ $off + 1 ] ) << 8 ) | ord( $d[ $off + 2 ] );
			}
			return ( ord( $d[ $off + 3 ] ) << 16 ) | ( ord( $d[ $off + 4 ] ) << 8 ) | ord( $d[ $off + 5 ] );
		}

		if ( 28 === $this->record_size ) {
			$mid = ord( $d[ $off + 3 ] );
			if ( 0 === $bit ) {
				return ( ( $mid >> 4 ) << 24 ) | ( ord( $d[ $off ] ) << 16 ) | ( ord( $d[ $off + 1 ] ) << 8 ) | ord( $d[ $off + 2 ] );
			}
			return ( ( $mid & 0x0F ) << 24 ) | ( ord( $d[ $off + 4 ] ) << 16 ) | ( ord( $d[ $off + 5 ] ) << 8 ) | ord( $d[ $off + 6 ] );
		}

		// record_size === 32.
		if ( 0 === $bit ) {
			return ( ord( $d[ $off ] ) << 24 ) | ( ord( $d[ $off + 1 ] ) << 16 ) | ( ord( $d[ $off + 2 ] ) << 8 ) | ord( $d[ $off + 3 ] );
		}
		return ( ord( $d[ $off + 4 ] ) << 24 ) | ( ord( $d[ $off + 5 ] ) << 16 ) | ( ord( $d[ $off + 6 ] ) << 8 ) | ord( $d[ $off + 7 ] );
	}

	/**
	 * Resolve a tree pointer to a decoded data record.
	 *
	 * @param int $pointer Record value from the search tree.
	 * @return mixed Decoded data (usually an associative array).
	 */
	private function read_record( $pointer ) {
		$min_pointer = $this->node_count + self::SEPARATOR_SIZE;
		if ( $pointer < $min_pointer ) {
			throw new \RuntimeException( 'Invalid MMDB data pointer: ' . (int) $pointer );
		}
		$data_offset = $pointer - $this->node_count - self::SEPARATOR_SIZE;
		$abs_offset  = $this->data_section_start + $data_offset;
		$this->assert_bytes_available( $abs_offset, 1 );
		return $this->decode( $abs_offset );
	}

	/**
	 * Decode a value from the data section at the given offset.
	 *
	 * @param int $offset Byte offset (modified in place to point past the decoded value).
	 * @param int $depth  Current recursion depth.
	 * @return mixed Decoded value.
	 */
	private function decode( &$offset, $depth = 0 ) {
		if ( $depth > self::MAX_DECODE_DEPTH ) {
			throw new \RuntimeException( 'MMDB decode exceeded maximum depth of ' . (int) self::MAX_DECODE_DEPTH );
		}
		$ctrl = $this->byte( $offset );
		$offset++;

		$type = ( $ctrl >> 5 ) & 7;

		// Type 1 = pointer — special handling.
		if ( 1 === $type ) {
			return $this->decode_pointer( $ctrl, $offset, $depth );
		}

		$size = $ctrl & 0x1F;

		// Extended type.
		if ( 0 === $type ) {
			$type = $this->byte( $offset ) + 7;
			$offset++;
		}

		// Resolve multi-byte size.
		if ( 29 === $size ) {
			$size   = 29 + $this->byte( $offset );
			$offset++;
		} elseif ( 30 === $size ) {
			$b      = $this->read_bytes( $offset, 2 );
			$size   = 285 + ( ord( $b[0] ) << 8 ) + ord( $b[1] );
			$offset += 2;
		} elseif ( 31 === $size ) {
			$b      = $this->read_bytes( $offset, 3 );
			$size   = 65821 + ( ord( $b[0] ) << 16 ) + ( ord( $b[1] ) << 8 ) + ord( $b[2] );
			$offset += 3;
		}

		return $this->decode_by_type( $type, $size, $offset, $depth );
	}

	/**
	 * Decode a pointer and resolve it.
	 *
	 * @param int $ctrl   Control byte.
	 * @param int $offset Current offset (advanced past pointer bytes).
	 * @param int $depth  Current recursion depth.
	 * @return mixed Decoded value at the pointer target.
	 */
	private function decode_pointer( $ctrl, &$offset, $depth = 0 ) {
		$ptr_size = ( $ctrl >> 3 ) & 3;
		$value    = $ctrl & 7;
		$pointer  = 0;
		$ptr_bytes = $ptr_size + 1; // 0→1, 1→2, 2→3, 3→4 bytes to read.
		$d         = $this->read_bytes( $offset, $ptr_bytes );

		switch ( $ptr_size ) {
			case 0:
				$pointer = ( $value << 8 ) + ord( $d[0] );
				$offset++;
				break;
			case 1:
				$pointer = 2048 + ( $value << 16 ) + ( ord( $d[0] ) << 8 ) + ord( $d[1] );
				$offset += 2;
				break;
			case 2:
				$pointer = 526336 + ( $value << 24 ) + ( ord( $d[0] ) << 16 ) + ( ord( $d[1] ) << 8 ) + ord( $d[2] );
				$offset += 3;
				break;
			case 3:
				$pointer = ( ord( $d[0] ) << 24 ) + ( ord( $d[1] ) << 16 ) + ( ord( $d[2] ) << 8 ) + ord( $d[3] );
				$offset += 4;
				break;
		}

		// Resolve — pointer is an offset from the start of the data section.
		$ptr_offset = $this->data_section_start + $pointer;
		return $this->decode( $ptr_offset, $depth + 1 );
	}

	/**
	 * Decode a typed value.
	 *
	 * @param int $type   MMDB data type.
	 * @param int $size   Data size.
	 * @param int $offset Current offset (advanced past data bytes).
	 * @param int $depth  Current recursion depth.
	 * @return mixed Decoded value.
	 */
	private function decode_by_type( $type, $size, &$offset, $depth = 0 ) {
		switch ( $type ) {
			case 2: // UTF-8 string.
				$str     = $this->read_bytes( $offset, $size );
				$offset += $size;
				return $str;

			case 5: // uint16.
			case 6: // uint32.
				$raw = $this->read_bytes( $offset, $size );
				$val = 0;
				for ( $i = 0; $i < $size; $i++ ) {
					$val = ( $val << 8 ) | ord( $raw[ $i ] );
				}
				$offset += $size;
				return $val;

			case 7: // map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$key = $this->decode( $offset, $depth + 1 );
					$val = $this->decode( $offset, $depth + 1 );
					if ( is_string( $key ) ) {
						$map[ $key ] = $val;
					}
				}
				return $map;

			case 8: // int32.
				$raw = $this->read_bytes( $offset, $size );
				$val = 0;
				for ( $i = 0; $i < $size; $i++ ) {
					$val = ( $val << 8 ) | ord( $raw[ $i ] );
				}
				$offset += $size;
				return ( $val >= 0x80000000 ) ? $val - 0x100000000 : $val;

			case 9: // uint64 — requires 64-bit PHP.
				if ( PHP_INT_SIZE < 8 ) {
					throw new \RuntimeException( 'MMDB uint64 requires 64-bit PHP.' );
				}
				$raw = $this->read_bytes( $offset, $size );
				$val = 0;
				for ( $i = 0; $i < $size; $i++ ) {
					$val = ( $val << 8 ) | ord( $raw[ $i ] );
				}
				$offset += $size;
				return $val;

			case 11: // array.
				$arr = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$arr[] = $this->decode( $offset, $depth + 1 );
				}
				return $arr;

			case 14: // boolean.
				return 0 !== $size;

			case 3: // double (8 bytes, big-endian).
				$raw     = $this->read_bytes( $offset, 8 );
				$offset += 8;
				$unpacked = unpack( 'E', $raw ); // PHP 7.2+ big-endian double.
				return false !== $unpacked ? $unpacked[1] : 0.0;

			case 4: // bytes.
				$raw     = $this->read_bytes( $offset, $size );
				$offset += $size;
				return $raw;

			case 15: // float (4 bytes, big-endian).
				$raw     = $this->read_bytes( $offset, 4 );
				$offset += 4;
				$unpacked = unpack( 'G', $raw ); // PHP 7.2+ big-endian float.
				return false !== $unpacked ? $unpacked[1] : 0.0;

			default:
				$offset += $size;
				return null;
		}
	}
}
