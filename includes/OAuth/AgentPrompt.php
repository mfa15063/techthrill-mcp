<?php
/**
 * The copy-and-paste prompt for AI agents.
 *
 * @package TechThrill\MCP
 */

namespace TechThrill\MCP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the prompt. It contains only public addresses, never a token.
 *
 * The prompt makes the agent keep its approved connection in a small private file and reuse it, because an agent
 * forgets everything between messages: without a saved file it would ask for approval again and again. An AI that
 * cannot run commands (an ordinary chat window) is told to stop and say so instead of pretending.
 */
final class AgentPrompt {

	/**
	 * File name (without folder) the agent saves its connection in, derived from the site's host name.
	 *
	 * @param string $siteUrl Site address.
	 */
	public static function fileName( string $siteUrl ): string {
		$host = (string) wp_parse_url( $siteUrl, PHP_URL_HOST );
		$host = (string) preg_replace( '/[^a-z0-9.-]+/', '-', strtolower( $host ) );

		return ( '' === trim( $host, '-.' ) ? 'site' : trim( $host, '-.' ) ) . '.json';
	}

	/**
	 * The prompt text.
	 *
	 * @param string $siteUrl  Site address.
	 * @param string $mcpUrl   MCP endpoint URL.
	 * @param string $startUrl URL that starts an access request.
	 * @param string $pollUrl  URL that reports the answer.
	 */
	public static function text( string $siteUrl, string $mcpUrl, string $startUrl, string $pollUrl ): string {
		$template = <<<'TXT'
Connect to my WordPress site and work on it for me through its MCP endpoint.

Site: {{SITE}}
MCP endpoint: {{MCP}}
Saved connection file: ~/.techthrill-mcp/{{FILE}} (on Windows: .techthrill-mcp\{{FILE}} in my user profile folder)

STEP 0 - Check that you can do this.
This needs an AI agent that can run shell commands or send HTTP POST requests, and keep a small file between messages, such as Claude Code, Codex or the Cursor agent. If you cannot run commands (for example you are an ordinary chat window without tools), do not continue and do not make up results. Reply only with this message and stop:
"I can't connect to this site from a normal chat. This is a work task that needs an AI agent that can run commands (Claude Code, Codex or Cursor), or an app and plan that support custom connectors (in Claude: Customize > Connectors > Add custom connector, then paste {{MCP}}). Please upgrade or switch to one of those and paste this prompt again."
If you can run commands but the files you write do not survive until my next message (for example a temporary sandbox), tell me that I would have to approve again every time, suggest an agent that keeps files or a custom connector, and wait for my decision.

STEP 1 - Reuse the saved connection before asking for anything.
Look for the saved connection file above. If it exists and its expires_at is empty (never expires) or still in the future, test it: POST {{MCP}} with the headers Authorization: Bearer <access_token>, Content-Type: application/json and Accept: application/json, first with this body {{INIT}} and then with {"jsonrpc":"2.0","id":2,"method":"tools/list"}.
- HTTP 200: the connection is good. Do NOT ask me to approve anything. Go to STEP 5.
- HTTP 401: the token expired, was revoked or is no longer valid. Delete the file and go to STEP 2.
- HTTP 200 but the tool you need is missing from tools/list, or a tool says the permission is missing: tell me which permission is missing, then go to STEP 2 and ask me to tick it.
Never ask me to approve again just because a new message or a new conversation started, or because you do not remember the connection. The file is your memory. Ask again only when the file is missing, the server answers 401, or you need a permission I did not give.

STEP 2 - Ask for access.
Run: curl -s -X POST "{{START}}" -H "Content-Type: application/json" -d '{"label":"<your own name, for example Claude Code>"}'
The JSON reply has request_id, poll_code, user_code, approval_url, expires_in and interval.

STEP 3 - Ask me to approve.
Show me approval_url and user_code, and ask me to open the link, log in as the site administrator, check that the code on the page matches user_code, tick the permissions I want to give you (all are ticked by default), choose how long the access lasts (30 days is the default) and press Approve. Do not open the link yourself. Never show poll_code to me or anyone else.

STEP 4 - Wait for my answer, then save the connection.
Every "interval" seconds run: curl -s -X POST "{{POLL}}" -H "Content-Type: application/json" -d '{"request_id":"<request_id>","poll_code":"<poll_code>"}'
Keep going until status is approved, denied or expired, for at most expires_in seconds. If it is denied or expired, tell me and stop. Do not ask again unless I tell you to.
When status is approved the reply contains access_token, scope and expires_at (empty means it never expires). Save them at once in the saved connection file above as JSON {"mcp_url":"{{MCP}}","access_token":"...","scope":"...","expires_at":...}. Create the folder so only I can open it (mode 700) and the file so only I can read it (mode 600; on Windows keep it inside my user profile folder). The token is a secret: write it only to that file, never print it, never put it in a project folder, never commit it and never repeat it in the chat.

STEP 5 - Use the connection.
For every call read access_token from the saved file and POST to {{MCP}} with the headers from STEP 1 and JSON-RPC 2.0 bodies: initialize, then tools/list and tools/call. If you support MCP natively you may add {{MCP}} as an HTTP MCP server with that Authorization header instead. Do not run STEP 2 again while the saved connection works.

STEP 6 - Report back.
Call the site_info tool and tell me in plain words which permissions I granted, what you can do on the site, and until when the access lasts. Only do what I ask next, and ask me before anything destructive. If a tool answers with a refusal, tell me what was refused and why instead of trying to get around it.
TXT;

		$init = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"agent","version":"1"}}}';

		return strtr(
			$template,
			array(
				'{{SITE}}'  => $siteUrl,
				'{{MCP}}'   => $mcpUrl,
				'{{FILE}}'  => self::fileName( $siteUrl ),
				'{{START}}' => $startUrl,
				'{{POLL}}'  => $pollUrl,
				'{{INIT}}'  => $init,
			)
		) . "\n";
	}
}
