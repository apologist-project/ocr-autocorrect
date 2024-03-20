<?php

namespace OcrAutoCorrect;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'unsquish',
    description: 'Automatically unsquish squished words.',
)]
class UnsquishCommand extends Command
{

    const WORD_BLACKLIST = [
        'ac',
        'alb',
        'ba',
        'bl',
        'ca',
        'ch',
        'com',
        'ctr',
        'dd',
        'de',
        'din',
        'don',
        'eccl',
        'elfin',
        'en',
        'er',
        'es',
        'est',
        'et',
        'eu',
        'fr',
        'fro',
        'gs',
        'ha',
        'hi',
        'hings',
        'ho',
        'hon',
        'ht',
        'id',
        'inf',
        'int',
        'ish',
        'ism',
        'la',
        'lexis',
        'lo',
        'ma',
        'mags',
        'mam',
        'mas',
        'mes',
        'mi',
        'min',
        'mus',
        'na',
        'nit',
        'nits',
        'nowt',
        'ow',
        'pi',
        'pow',
        'pre',
        'rah',
        're',
        'rec',
        'religio',
        'soc',
        'tr',
        'un',
        'uni',
        'wert',
        'wit',
        'yin',
        'yo',
        'yous',
    ];

    /**
     * @param string $error
     * @param array $suggestions
     * @param array $words
     * @param int $i
     * @param string $context
     * @param string $file
     * @return array
     */
    protected function getReplacement(
        string $error,
        array $suggestions,
        array $words,
        int $i,
        string $context,
        string $file
    ): array
    {

        $possibilities = [];
        $numChars = strlen($error);
        $firstWords = $this->getWords($error);
        $results = [];
        foreach ($firstWords as $firstWord)
        {
            $str = substr($error, strlen($firstWord));
            $possibilities[$firstWord] = $this->getWords($str);
            foreach ($possibilities[$firstWord] as $secondWord)
            {
                $allWords = [$firstWord, $secondWord];
                $fullStr = implode('', $allWords);
                $wordsLen = strlen($fullStr);
                if ($wordsLen == $numChars) {
                    $results[] = implode(' ', $allWords);
                }
                $str2 = substr($error, strlen($fullStr));
                $possibilities[$firstWord][$secondWord] = $this->getWords($str2);
                foreach ($possibilities[$firstWord][$secondWord] as $thirdWord)
                {
                    $allWords = [$firstWord, $secondWord, $thirdWord];
                    $fullStr = implode('', $allWords);
                    $wordsLen = strlen($fullStr);
                    if ($wordsLen == $numChars) {
                        $results[] = implode(' ', $allWords);
                    }
                    $str3 = substr($error, strlen($firstWord . $secondWord . $thirdWord));
                    $possibilities[$firstWord][$secondWord][$thirdWord] = $this->getWords($str3);
                    foreach ($possibilities[$firstWord][$secondWord][$thirdWord] as $fourthWord)
                    {
                        $allWords = [$firstWord, $secondWord, $thirdWord, $fourthWord];
                        $fullStr = implode('', $allWords);
                        $wordsLen = strlen($fullStr);
                        if ($wordsLen == $numChars) {
                            $results[] = implode(' ', $allWords);
                        }
                    }
                }
            }
        }

        usort($results, function($a, $b){
            return ((strlen($a) > strlen($b)) ? 1 : -1);
        });

//        var_dump($results);

        $correction = null;
        if (!empty($results)) {
            $correction = $results[0];
        }

        return [
            'error'     =>  $error,
            'correction'=>  $correction,
            'ff'        =>  false,
        ];

    }

    /**
     * @param $str
     * @return array
     */
    protected function getWords($str): array
    {
        $numChars = strlen($str);
        $words = [];
        for ($i = 1; $i <= $numChars; ++$i)
        {
            $word = substr($str, 0, $i);
            if (
                (strlen($word) > 1) &&
                (!in_array(strtolower($word), static::WORD_BLACKLIST)) &&
                pspell_check($this->dict, $word)
            ) {
                $words[] = $word;
            }
        }
        return $words;
    }

}