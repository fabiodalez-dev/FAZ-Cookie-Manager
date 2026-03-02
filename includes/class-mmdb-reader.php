<?php
/**
 * Minimal MaxMind DB (.mmdb) reader for GeoLite2 Country lookups.
 *
 * Supports record sizes 24, 28, and 32 bits.
 * Reads the full file into memory (~5 MB for GeoLite2-Country).
 *
 * @package FazCookie\Includes
 */

namespace FazCookie\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mmdb_Reader {

	/**
	 * Raw file contents.
	 *
	 * @var string
	 */
	private $data;

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

	const SEPARATOR_SIZE  = 16;
	const METADATA_MARKER = "\xab\xcd\xefMaxMind.com";

	/**
	 * Open and parse an MMDB file.
	 *
	 * @param string $file Absolute path to the .mmdb file.
	 * @throws \RuntimeException If the file cannot be read or is invalid.
	 */
	public function __construct( $file ) {
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			throw new \RuntimeException( 'Cannot read MMDB file: ' . $file );
		}
		$this->data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $this->data ) {
			throw new \RuntimeException( 'Cannot read MMDB file: ' . $file );
		}
		$this->parse_metadata();
		$this->data_section_start = $this->search_tree_size + self::SEPARATOR_SIZE;
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
	 * Parse metadata from the end of the file.
	 *
	 * @throws \RuntimeException If metadata marker is not found.
	 */
	private function parse_metadata() {
		$pos = strrpos( $this->data, self::METADATA_MARKER );
		if ( false === $pos ) {
			throw new \RuntimeException( 'Invalid MMDB file: metadata marker not found.' );
		}
		$offset = $pos + strlen( self::METADATA_MARKER );
		$meta   = $this->decode( $offset );
		if ( ! is_array( $meta ) || ! isset( $meta['node_count'], $meta['record_size'], $meta['ip_version'] ) ) {
			throw new \RuntimeException( 'Invalid MMDB metadata.' );
		}
		$this->node_count       = (int) $meta['node_count'];
		$this->record_size      = (int) $meta['record_size'];
		if ( ! in_array( $this->record_size, array( 24, 28, 32 ), true ) ) {
			throw new \RuntimeException( 'Unsupported MMDB record size: ' . $this->record_size );
		}
		$this->ip_version       = (int) $meta['ip_version'];
		if ( ! in_array( $this->ip_version, array( 4, 6 ), true ) ) {
			throw new \RuntimeException( 'Unsupported MMDB ip_version: ' . $this->ip_version );
		}
		$this->node_byte_size   = (int) ( $this->record_size * 2 / 8 );
		$this->search_tree_size = $this->node_count * $this->node_byte_size;
		if ( $this->search_tree_size + self::SEPARATOR_SIZE > strlen( $this->data ) ) {
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
		if ( $offset + $needed > strlen( $this->data ) ) {
			throw new \RuntimeException( 'MMDB file is truncated at offset ' . $offset . ' (need ' . $needed . ' bytes).' );
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
		$off = $node_num * $this->node_byte_size;
		$this->assert_bytes_available( $off, $this->node_byte_size );
		$d   = $this->data;

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
		$data_offset = $pointer - $this->node_count - self::SEPARATOR_SIZE;
		$abs_offset  = $this->data_section_start + $data_offset;
		return $this->decode( $abs_offset );
	}

	/**
	 * Decode a value from the data section at the given offset.
	 *
	 * @param int $offset Byte offset (modified in place to point past the decoded value).
	 * @return mixed Decoded value.
	 */
	private function decode( &$offset ) {
		$this->assert_bytes_available( $offset, 1 );
		$ctrl = ord( $this->data[ $offset ] );
		$offset++;

		$type = ( $ctrl >> 5 ) & 7;

		// Type 1 = pointer — special handling.
		if ( 1 === $type ) {
			return $this->decode_pointer( $ctrl, $offset );
		}

		$size = $ctrl & 0x1F;

		// Extended type.
		if ( 0 === $type ) {
			$type = ord( $this->data[ $offset ] ) + 7;
			$offset++;
		}

		// Resolve multi-byte size.
		if ( 29 === $size ) {
			$size   = 29 + ord( $this->data[ $offset ] );
			$offset++;
		} elseif ( 30 === $size ) {
			$size   = 285 + ( ord( $this->data[ $offset ] ) << 8 ) + ord( $this->data[ $offset + 1 ] );
			$offset += 2;
		} elseif ( 31 === $size ) {
			$size   = 65821 + ( ord( $this->data[ $offset ] ) << 16 ) + ( ord( $this->data[ $offset + 1 ] ) << 8 ) + ord( $this->data[ $offset + 2 ] );
			$offset += 3;
		}

		return $this->decode_by_type( $type, $size, $offset );
	}

	/**
	 * Decode a pointer and resolve it.
	 *
	 * @param int $ctrl   Control byte.
	 * @param int $offset Current offset (advanced past pointer bytes).
	 * @return mixed Decoded value at the pointer target.
	 */
	private function decode_pointer( $ctrl, &$offset ) {
		$ptr_size = ( $ctrl >> 3 ) & 3;
		$value    = $ctrl & 7;
		$d        = $this->data;
		$pointer  = 0;
		$ptr_bytes = $ptr_size + 1; // 0→1, 1→2, 2→3, 3→4 bytes to read.
		$this->assert_bytes_available( $offset, $ptr_bytes );

		switch ( $ptr_size ) {
			case 0:
				$pointer = ( $value << 8 ) + ord( $d[ $offset ] );
				$offset++;
				break;
			case 1:
				$pointer = 2048 + ( $value << 16 ) + ( ord( $d[ $offset ] ) << 8 ) + ord( $d[ $offset + 1 ] );
				$offset += 2;
				break;
			case 2:
				$pointer = 526336 + ( $value << 24 ) + ( ord( $d[ $offset ] ) << 16 ) + ( ord( $d[ $offset + 1 ] ) << 8 ) + ord( $d[ $offset + 2 ] );
				$offset += 3;
				break;
			case 3:
				$pointer = ( ord( $d[ $offset ] ) << 24 ) + ( ord( $d[ $offset + 1 ] ) << 16 ) + ( ord( $d[ $offset + 2 ] ) << 8 ) + ord( $d[ $offset + 3 ] );
				$offset += 4;
				break;
		}

		// Resolve — pointer is an offset from the start of the data section.
		$ptr_offset = $this->data_section_start + $pointer;
		return $this->decode( $ptr_offset );
	}

	/**
	 * Decode a typed value.
	 *
	 * @param int $type   MMDB data type.
	 * @param int $size   Data size.
	 * @param int $offset Current offset (advanced past data bytes).
	 * @return mixed Decoded value.
	 */
	private function decode_by_type( $type, $size, &$offset ) {
		switch ( $type ) {
			case 2: // UTF-8 string.
				$str     = substr( $this->data, $offset, $size );
				$offset += $size;
				return $str;

			case 5: // uint16.
			case 6: // uint32.
				$val = 0;
				for ( $i = 0; $i < $size; $i++ ) {
					$val = ( $val << 8 ) | ord( $this->data[ $offset + $i ] );
				}
				$offset += $size;
				return $val;

			case 7: // map.
				$map = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$key = $this->decode( $offset );
					$val = $this->decode( $offset );
					if ( is_string( $key ) ) {
						$map[ $key ] = $val;
					}
				}
				return $map;

			case 8: // int32.
				$val = 0;
				for ( $i = 0; $i < $size; $i++ ) {
					$val = ( $val << 8 ) | ord( $this->data[ $offset + $i ] );
				}
				$offset += $size;
				return ( $val >= 0x80000000 ) ? $val - 0x100000000 : $val;

			case 9: // uint64 — requires 64-bit PHP.
				if ( PHP_INT_SIZE < 8 ) {
					throw new \RuntimeException( 'MMDB uint64 requires 64-bit PHP.' );
				}
				$val = 0;
				for ( $i = 0; $i < $size; $i++ ) {
					$val = ( $val << 8 ) | ord( $this->data[ $offset + $i ] );
				}
				$offset += $size;
				return $val;

			case 11: // array.
				$arr = array();
				for ( $i = 0; $i < $size; $i++ ) {
					$arr[] = $this->decode( $offset );
				}
				return $arr;

			case 14: // boolean.
				return 0 !== $size;

			case 3: // double (8 bytes, big-endian).
				$raw     = substr( $this->data, $offset, 8 );
				$offset += 8;
				$unpacked = unpack( 'E', $raw ); // PHP 7.2+ big-endian double.
				return false !== $unpacked ? $unpacked[1] : 0.0;

			case 4: // bytes.
				$raw     = substr( $this->data, $offset, $size );
				$offset += $size;
				return $raw;

			case 15: // float (4 bytes, big-endian).
				$raw     = substr( $this->data, $offset, 4 );
				$offset += 4;
				$unpacked = unpack( 'G', $raw ); // PHP 7.2+ big-endian float.
				return false !== $unpacked ? $unpacked[1] : 0.0;

			default:
				$offset += $size;
				return null;
		}
	}
}
