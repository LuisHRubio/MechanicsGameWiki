<?php
declare( strict_types = 1 );

namespace Wikimedia\Parsoid\NodeData;

use Wikimedia\JsonCodec\JsonCodec;
use Wikimedia\JsonCodec\JsonCodecable;
use Wikimedia\JsonCodec\JsonCodecableTrait;

/**
 * Localizable errors, stored in data-mw.
 */
class DataMwError implements JsonCodecable {
	use JsonCodecableTrait;

	/**
	 * The error name, as a localizable key.
	 */
	public string $key;

	/**
	 * An unlocalized version of the error name, as a fallback.
	 */
	public ?string $message;

	/**
	 * Optional parameters for the error message.
	 * These should all be codecable.
	 */
	public array $params;

	public function __construct( string $key, array $params = [], ?string $message = null ) {
		$this->key = $key;
		$this->params = $params;
		$this->message = $message;
	}

	public function __clone() {
		if ( $this->params ) {
			$codec = new JsonCodec;
			$this->params = $codec->newFromJsonArray(
				$codec->toJsonArray( $this->params )
			);
		}
	}

	public function equals( DataMwError $other ): bool {
		// Use non-strict equality test, which will compare the properties
		// and compare the values in the params array.
		// @phan-suppress-next-line PhanPluginComparisonObjectEqualityNotStrict
		return $this == $other;
	}

	/** @inheritDoc */
	public function toJsonArray(): array {
		$result = [ 'key' => $this->key ];
		if ( $this->message !== null ) {
			$result['message'] = $this->message;
		}
		if ( $this->params !== [] ) {
			$result['params'] = $this->params;
		}
		return $result;
	}

	/** @inheritDoc */
	public static function newFromJsonArray( array $json ): DataMwError {
		return new DataMwError( $json['key'], $json['params'] ?? [], $json['message'] ?? null );
	}
}
