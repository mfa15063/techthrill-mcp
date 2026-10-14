<?php
/**
 * JSON-RPC response envelopes.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * JSON-RPC 2.0 error codes and envelope builders.
 */
final class JsonRpcError {

	const PARSE            = -32700;
	const INVALID_REQUEST  = -32600;
	const METHOD_NOT_FOUND = -32601;
	const INVALID_PARAMS   = -32602;
	const INTERNAL         = -32603;
	const UNAUTHORIZED     = -32001;
	const RATE_LIMITED     = -32002;

	/**
	 * Builds an error response.
	 *
	 * @param int|string|null $id      Request ID.
	 * @param int             $code    Error code.
	 * @param string          $message Error message.
	 * @param mixed           $data    Optional error data; omitted when null.
	 */
	public static function envelope( $id, int $code, string $message, $data = null ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * Builds a success response.
	 *
	 * @param int|string|null $id     Request ID.
	 * @param mixed           $result Result payload.
	 */
	public static function success( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}
}
