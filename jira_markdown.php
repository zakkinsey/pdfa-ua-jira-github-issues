<?php

function getDirTxtJiraFormat($j2mDir) {
    return "$j2mDir/jf";
}

function getDirTxtGithubMarkdown($j2mDir) {
    return "$j2mDir/md";
}

function exportAndMarkdown($j2mDir, $file, $jfText) {
	$file = preg_replace('/:/', '-', $file);
    $jfDir = getDirTxtJiraFormat(    $j2mDir);
    $mdDir = getDirTxtGithubMarkdown($j2mDir);

    $mdText = toMarkdown($jfText);

    file_put_contents("$jfDir/$file", $jfText);
    file_put_contents("$mdDir/$file", $mdText);

    return $mdText;
}

function toMarkdown($text) {
    $converted = $text;
    if ($converted == null) {
        $converted = '';
    }

    $converted = preg_replace('/\r\n/', "\n", $converted);
    $converted = preg_replace('/{color(:#[\da-f]+)?}/', '', $converted);

    $codeBlockMarker = 'jira2githubMarkdown';
    $codeBlocks = [];
    $converted = preg_replace_callback(
        '/{code(:(\S+))?}((?:\\{|[^{]|{(?!code))+){code}\n?/ms',
        function ($matches) use($codeBlockMarker, &$codeBlocks) {
            $retVal = "$codeBlockMarker:" . count($codeBlocks);
            $codeBlocks[] = '```' . $matches[2] . $matches[3] . '```' . "\n";
            return $retVal;
        },
        $converted
    );

    $converted = preg_replace('/<\S+>/',   '`\0`',  $converted);

    $converted = preg_replace('/&/',       '&amp;', $converted);
    $converted = preg_replace('/(?<!`)</', '&lt;',  $converted);
    $converted = preg_replace('/>(?!`)/' , '&gt;',  $converted);

    foreach ($codeBlocks as $codeBlockIndex => $codeBlock) {
        $converted = preg_replace("/$codeBlockMarker:$codeBlockIndex/", $codeBlock, $converted);
    }

    $converted = preg_replace_callback('/{quote}\n?(([^{]|{(?!quote))*)({quote}\n?)?/s', function ($matches) {
        return "\n" . preg_replace('/^/m', '>', $matches[1]) . "\n";
    }, $converted);

    $converted = preg_replace_callback('/^([-*#]*)([-*#]) /m', function ($matches) {
        $indent = ' ';
        foreach(str_split($matches[1]) as $char){
            if ($char == '#') {
                $indent .= '   ';
            } else {
                $indent .= '  ';
            }
        }
        $listOp = $matches[2];
        if ($listOp == '#') {
            $listOp = '1.';
        }
        return "$indent$listOp ";
    }, $converted);

    $converted = preg_replace_callback('/^h([0-6])\.(.*)$/m', function ($matches) {
        return str_repeat('#', $matches[1]) . $matches[2];
    }, $converted);

    $converted = preg_replace_callback(
        '/([cC]heck|[tT]est|[sS]tep)s? +#\d+|#\d+(.*(is|are( all)?) (true|false))/',
        function ($matches) {
            return preg_replace('/#(\d+)/', '#&#x2060;$1', $matches[0]);
        },
        $converted
    );

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
    $converted = preg_replace('/\\\{/', '{', $converted);
    //$converted = preg_replace('/\[(.+?)\]([^\(]*)/', '<$1>$2', $converted);

    $converted = preg_replace('/{noformat}/', '```', $converted);

    $converted = preg_replace('{(https://jira.pdfa.org/browse/)?PDFUA-(\d+)}', '#$2', $converted);

    return $converted;
}
