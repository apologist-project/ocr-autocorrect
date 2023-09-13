<?php

namespace OcrAutoCorrect;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'correct',
    description: 'Auto-corrects all known errors and leaves the rest alone.',
)]
class CorrectCommand extends Command
{

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
        return [
            'error'     =>  $error,
            'correction'=>  $suggestions[0],
            'ff'        =>  false,
        ];
    }

}