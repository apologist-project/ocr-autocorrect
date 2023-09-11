<?php
require_once('src/OcrAutoCorrect/OcrAutoCorrect.php');

use OcrAutoCorrect\OcrAutoCorrect;


$words = [
    'frty',
    'ififa',
    'tjf',
    'COMMENTARIE',
    'LVTHER',
    'VPONTHE',
    'PAVL',
    'THEGALATHIANS',
    'firft',
    'Latine',
    'tranflated',
    'Englifh',
    'fet',
    'moft',
    'Gofpell',
    'betmene',
    'Gofpdland',
    'theftrength',
    'ofFiith',
    'joyfull',
    'cenfirmation',
    'efpecially',

];

OcrAutoCorrect::init();
//OcrAutoCorrect::resetDb();

foreach ($words as $word) {
    echo "{$word}\n";
    $suggestions = OcrAutoCorrect::getSuggestions($word);
    OcrAutoCorrect::saveCorrection($word, $suggestions[array_rand($suggestions)], "blah {$word} blah", "myfile.txt");
    print_r($suggestions);
}

