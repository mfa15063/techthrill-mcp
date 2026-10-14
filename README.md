# TechThrill MCP

TechThrill MCP turns a WordPress site into an MCP (Model Context Protocol) server, so AI clients such as Claude Code or Codex can connect to the site and work with it through a controlled set of tools.

## Development setup

```bash
composer install
composer test   # run the PHPUnit suite
composer lint   # run PHP_CodeSniffer
```

Tests run without WordPress loaded; `tests/bootstrap.php` stubs the WordPress functions the plugin needs.

## Manual verification checklist
