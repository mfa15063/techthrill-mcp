<?php
/**
 * The copy-and-paste prompt for AI agents.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the prompt. It contains only public addresses, never a token. An AI that cannot run commands or send
 * POST requests (an ordinary chat window) is told to stop and say so instead of pretending.
 */
final class AgentPrompt {

	/**
	 * The prompt text.
	 *
	 * @param string $siteUrl  Site address.
	 * @param string $mcpUrl   MCP endpoint URL.
	 * @param string $startUrl URL that starts an access request.
	 * @param string $pollUrl  URL that reports the answer.
	 */
	public static function text( string $siteUrl, string $mcpUrl, string $startUrl, string $pollUrl ): string {
		$init = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"agent","version":"1"}}}';

		return "Connect to my WordPress site and work on it for me through its MCP endpoint.\n\n"
			. "Site: {$siteUrl}\n"
			. "MCP endpoint: {$mcpUrl}\n\n"
			. "STEP 0 - Check that you can do this.\n"
			. "This needs an AI agent that can run shell commands or send HTTP POST requests, such as Claude Code, Codex or the Cursor agent. If you cannot (for example you are an ordinary chat window without tools), do not continue and do not make up results. Reply only with this message and stop:\n"
			. "\"I can't connect to this site from a normal chat. This is a work task that needs an AI agent that can run commands (Claude Code, Codex or Cursor), or an app and plan that support custom connectors (in Claude: Customize > Connectors > Add custom connector, then paste {$mcpUrl}). Please upgrade or switch to one of those and paste this prompt again.\"\n\n"
			. "STEP 1 - Ask for access.\n"
			. "Run: curl -s -X POST \"{$startUrl}\" -H \"Content-Type: application/json\" -d '{\"label\":\"<your own name, for example Claude Code>\"}'\n"
			. "The JSON reply has request_id, poll_code, user_code, approval_url, expires_in and interval.\n\n"
			. "STEP 2 - Ask me to approve.\n"
			. "Show me approval_url and user_code, and ask me to open the link, log in as the site administrator, check that the code on the page matches user_code, tick the permissions I want to give you and press Approve. Do not open the link yourself. Never show poll_code to me or anyone else.\n\n"
			. "STEP 3 - Wait for my answer.\n"
			. "Every \"interval\" seconds run: curl -s -X POST \"{$pollUrl}\" -H \"Content-Type: application/json\" -d '{\"request_id\":\"<request_id>\",\"poll_code\":\"<poll_code>\"}'\n"
			. "Keep going until status is approved, denied or expired, for at most expires_in seconds. If it is denied or expired, tell me and stop. Do not ask again unless I tell you to.\n\n"
			. "STEP 4 - Use the access.\n"
			. "When status is approved the reply contains access_token and scope. The token is a secret: keep it only in memory or an environment variable for this session, never print it, never write it to a file, never commit it. Call the endpoint with POST {$mcpUrl}, headers Authorization: Bearer <access_token>, Content-Type: application/json and Accept: application/json, and JSON-RPC 2.0 bodies. Start with {$init} then use tools/list and tools/call. If you support MCP natively you may add {$mcpUrl} as an HTTP MCP server with that Authorization header instead.\n\n"
			. "STEP 5 - Report back.\n"
			. "Call the site_info tool and tell me in plain words which permissions I granted and what you can do on the site. Only do what I ask next, and ask me before anything destructive. If a tool answers with a refusal, tell me what was refused and why instead of trying to get around it.\n";
	}
}
