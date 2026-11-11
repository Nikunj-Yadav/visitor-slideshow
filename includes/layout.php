<?php
/** Shared page header/footer so every page has the same <head>. */

function page_head(string $title, array $o = []): void
{
    $base = h(base_url());
    echo "<!doctype html>\n<html lang=\"en\">\n<head>\n";
    echo "  <meta charset=\"utf-8\">\n  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    if (!empty($o['noindex'])) { echo "  <meta name=\"robots\" content=\"noindex\">\n"; }
    if (!empty($o['description'])) { echo '  <meta name="description" content="' . h($o['description']) . "\">\n"; }
    echo '  <title>' . h($title) . " · Memory Wall</title>\n";
    echo "  <base href=\"{$base}\">\n";   // all other URLs on the page are relative to /public
    if (!empty($o['csrf'])) { echo '  <meta name="csrf-token" content="' . h(csrf_token()) . "\">\n"; }
    echo "  <link rel=\"icon\" href=\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect width='24' height='24' rx='6' fill='%2317687a'/%3E%3C/svg%3E\">\n";
    echo "  <link rel=\"stylesheet\" href=\"css/app.css\">\n  <script src=\"js/common.js\"></script>\n</head>\n<body>\n";
}

function page_foot(string $script): void
{
    echo '  <script src="js/' . h($script) . "\"></script>\n</body>\n</html>\n";
}
