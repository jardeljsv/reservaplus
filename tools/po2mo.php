<?php

declare(strict_types=1);

/**
 * Compila os .po do plugin em .mo, sem depender do gettext do sistema.
 *
 * A imagem do GLPI não traz `msgfmt` nem uma lib de gettext com escrita, então
 * a alternativa seria editar o .mo na mão (binário) ou deixar as strings novas
 * sem tradução. Este compilador resolve as duas coisas.
 *
 * Uso:
 *   php tools/po2mo.php                 # compila locales/*.po
 *   php tools/po2mo.php pt_BR en_US     # compila só os locales indicados
 */

$localesDir = dirname(__DIR__) . '/locales';
$locales    = array_slice($argv, 1);

if ($locales === []) {
    foreach (glob($localesDir . '/*.po') ?: [] as $file) {
        $locales[] = basename($file, '.po');
    }
}

$exit = 0;
foreach ($locales as $locale) {
    $po = $localesDir . '/' . $locale . '.po';
    $mo = $localesDir . '/' . $locale . '.mo';

    if (!is_file($po)) {
        fwrite(STDERR, "FALTA: {$po}\n");
        $exit = 1;
        continue;
    }

    $entries = parsePo(file_get_contents($po));
    if ($entries === []) {
        fwrite(STDERR, "VAZIO: {$po}\n");
        $exit = 1;
        continue;
    }

    file_put_contents($mo, buildMo($entries));
    printf("OK  %s.mo — %d entradas\n", $locale, count($entries));
}

exit($exit);

/**
 * Lê um .po e devolve [msgid_com_contexto => traducao], no formato binário que
 * o .mo espera (plural = msgid\0msgid_plural, traduções separadas por \0).
 *
 * @return array<string,string>
 */
function parsePo(string $content): array
{
    $entries = [];
    $current = resetEntry();
    $lastKey = null;

    foreach (preg_split('/\R/', $content) ?: [] as $line) {
        $line = trim($line);

        if ($line === '') {
            storeEntry($entries, $current);
            $current = resetEntry();
            $lastKey = null;
            continue;
        }
        if ($line[0] === '#') {
            continue;
        }

        if (preg_match('/^msgctxt\s+"(.*)"$/', $line, $m)) {
            $current['msgctxt'] = unescapePo($m[1]);
            $lastKey = 'msgctxt';
        } elseif (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            $current['msgid'] = unescapePo($m[1]);
            $lastKey = 'msgid';
        } elseif (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $m)) {
            $current['msgid_plural'] = unescapePo($m[1]);
            $lastKey = 'msgid_plural';
        } elseif (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $line, $m)) {
            $current['msgstr'][(int) $m[1]] = unescapePo($m[2]);
            $lastKey = 'msgstr[' . (int) $m[1] . ']';
        } elseif (preg_match('/^msgstr\s+"(.*)"$/', $line, $m)) {
            $current['msgstr'][0] = unescapePo($m[1]);
            $lastKey = 'msgstr[0]';
        } elseif (preg_match('/^"(.*)"$/', $line, $m) && $lastKey !== null) {
            // Continuação de linha: "..." solto logo abaixo do campo anterior.
            $text = unescapePo($m[1]);
            if (preg_match('/^msgstr\[(\d+)\]$/', $lastKey, $mm)) {
                $current['msgstr'][(int) $mm[1]] .= $text;
            } else {
                $current[$lastKey] .= $text;
            }
        }
    }

    storeEntry($entries, $current);

    return $entries;
}

function resetEntry(): array
{
    return ['msgctxt' => null, 'msgid' => null, 'msgid_plural' => null, 'msgstr' => []];
}

/** @param array<string,string> $entries */
function storeEntry(array &$entries, array $entry): void
{
    if ($entry['msgid'] === null || $entry['msgstr'] === []) {
        return;
    }

    ksort($entry['msgstr']);
    $translation = implode("\0", $entry['msgstr']);

    // Traduzido para vazio = sem tradução; deixa o gettext cair no msgid.
    if (trim($translation) === '' && $entry['msgid'] !== '') {
        return;
    }

    $key = $entry['msgid'];
    if ($entry['msgid_plural'] !== null) {
        $key .= "\0" . $entry['msgid_plural'];
    }
    if ($entry['msgctxt'] !== null) {
        $key = $entry['msgctxt'] . "\4" . $key;
    }

    $entries[$key] = $translation;
}

function unescapePo(string $value): string
{
    return strtr($value, [
        '\\n'  => "\n",
        '\\t'  => "\t",
        '\\r'  => "\r",
        '\\"'  => '"',
        '\\\\' => '\\',
    ]);
}

/**
 * Monta o binário .mo (formato GNU gettext, little-endian, sem hash table —
 * o leitor do GLPI faz busca binária na tabela de originais).
 *
 * @param array<string,string> $entries
 */
function buildMo(array $entries): string
{
    ksort($entries, SORT_STRING);

    $count       = count($entries);
    $originals   = '';
    $translations = '';
    $originalTable    = [];
    $translationTable = [];

    foreach ($entries as $msgid => $msgstr) {
        $originalTable[]    = [strlen($msgid), strlen($originals)];
        $originals         .= $msgid . "\0";
        $translationTable[] = [strlen($msgstr), strlen($translations)];
        $translations      .= $msgstr . "\0";
    }

    $headerSize        = 28;
    $originalTableOff  = $headerSize;
    $translationOff    = $originalTableOff + $count * 8;
    $originalsOff      = $translationOff + $count * 8;
    $translationsOff   = $originalsOff + strlen($originals);

    $mo = pack('V', 0x950412de)  // magic
        . pack('V', 0)           // revisão
        . pack('V', $count)
        . pack('V', $originalTableOff)
        . pack('V', $translationOff)
        . pack('V', 0)           // tamanho da hash table (não usada)
        . pack('V', $translationsOff + strlen($translations));

    foreach ($originalTable as [$len, $off]) {
        $mo .= pack('VV', $len, $originalsOff + $off);
    }
    foreach ($translationTable as [$len, $off]) {
        $mo .= pack('VV', $len, $translationsOff + $off);
    }

    return $mo . $originals . $translations;
}
