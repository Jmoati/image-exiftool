<?php

// Reads {"id": "php closure code", ...} as JSON on stdin, prints the ids whose code doesn't parse
// (one "id<TAB>message" per line). Used by export-tables.pl to skip broken translations.

declare(strict_types=1);

$entries = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
foreach ($entries as $id => $code) {
    try {
        token_get_all("<?php\nnamespace Image\\ExifTool;\n\$x = $code;\n", TOKEN_PARSE);
    } catch (\ParseError $e) {
        echo $id, "\t", $e->getMessage(), ' (line ', $e->getLine() - 2, ")\n";
    }
}
