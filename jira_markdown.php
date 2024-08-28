<?php

function toMarkdown($text) {
    $converted = $text;
    if ($converted == null) {
        $converted = '';
    }
    $converted = preg_replace_callback('/^h([0-6])\.(.*)$/m', function ($matches) {
        return str_repeat('#', $matches[1]) . $matches[2];
    }, $converted);

    $converted = preg_replace_callback('/([*_])(.*)\1/', function ($matches) {
        list ($match, $wrapper, $content) = $matches;
        $to = ($wrapper === '*') ? '**' : '*';
        return $to . $content . $to;
    }, $converted);

    $converted = preg_replace('/\{\{([^}]+)\}\}/', '`$1`', $converted);
    $hackSave = $converted;
    $converted = preg_replace('/\?\?((?:.[^?]|[^?].)+)\?\?/', '<cite>$1</cite>', $converted);
    if ($converted == null) {
        $converted = $hackSave;
    }
    $converted = preg_replace('/\+([^+]*)\+/', '<ins>$1</ins>', $converted);
    $converted = preg_replace('/\^([^^]*)\^/', '<sup>$1</sup>', $converted);
    $converted = preg_replace('/~([^~]*)~/', '<sub>$1</sub>', $converted);
    $converted = preg_replace('/-([^-]*)-/', '-$1-', $converted);

    $converted = preg_replace('/{code(:([a-z]+))?}/', '```$2', $converted);
    $converted = preg_replace('/{code(:([^}]+))?}/', '```', $converted);

    $converted = preg_replace('/\[(.+?)\|(.+?)\]/', '[$1]($2)', $converted);
    //$converted = preg_replace('/\[(.+?)\]([^\(]*)/', '<$1>$2', $converted);

    $converted = preg_replace('/{noformat}/', '```', $converted);

    // TODO: fix regex
    $converted = preg_replace_callback('/((DBAL|DCOM|DDC|DMIG)+-([0-9]+))/', function ($matches) {
        return '[' . $matches[1] . '](' . getenv('JIRA_URL') . '/jira/browse/' . $matches[1] . ')';
    }, $converted);

    return $converted;
}
