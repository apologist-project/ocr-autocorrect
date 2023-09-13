<?php
namespace OcrAutoCorrect;

use SQLite3Result;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'train',
    description: 'Finds errors and prompts the user to select the best correction or provide their own.',
)]
class TrainCommand extends Command
{

    const CHOICES = [
        's' =>  'Skip',
        'q' =>  'Quit',
        'e' =>  'Enter custom value',
        'c' =>  'Correct expanded context',
        'k' =>  'Keep as-is',
        'r' =>  'Remove',
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

        $options = $this->getOptions($suggestions);
        $selection = $this->out->choice(
            'What do you want to do with this possible error?',
            $options,
            's'
        );

        $correction = null;
        $ff = false;
        if ($selection == 's') {
            $this->out->comment('Skipped');
        } else if ($selection == 'q') {
            $this->out->block('Goodbye!');
            exit;
        } else {

            if ($selection == 'k') {
                $correction = $error;
            } else if ($selection == 'r') {
                $correction = '';
            } else if ($selection == 'e') {
                $correction = $this->out->ask('Enter custom value');
            } else if ($selection == 'c') {
                $error = '';
                if (isset($words[$i-1])) {
                    $error .= "{$words[$i-1]} ";
                }
                $error .= $words[$i];
                if (isset($words[$i+1])) {
                    $error .= " {$words[$i+1]}";
                }
                $correction = $this->out->ask("What would you like to replace <fg=white;bg=red>{$error}</> with?");
                $ff = true;
            } else {
                $correction = $options[$selection];
            }

            if (!$this->saveCorrection($error, $correction, $context, $file)) {
                $this->out->error("Error saving correction to database");
            }

            if ($selection == 'k') {
                $this->out->comment('Kept as-is');
            }

        }

        return [
            'error'     =>  $error,
            'correction'=>  $correction,
            'ff'        =>  $ff,
        ];

    }

    /**
     * @param array $suggestions
     * @return array
     */
    protected function getOptions(array $suggestions): array
    {
        $options = static::CHOICES;
        array_unshift($suggestions, 'dummy');
        array_splice( $options, 6, 0, $suggestions);
        unset($options[0]);
        return $options;
    }

    /**
     * @param string $error
     * @param string $correction
     * @param string $context
     * @param string $source
     * @return SQLite3Result
     */
    public function saveCorrection(string $error, string $correction, string $context, string $source): SQLite3Result
    {

        $qry = $this->db->prepare("
            INSERT INTO
                corrections
            (
                error,
                correction,
                context,
                `source`
            )
            VALUES 
            (
                :error,
                :correction,
                :context,
                :source
            )
        ");

        $qry->bindValue(':error', $error, SQLITE3_TEXT);
        $qry->bindValue(':correction', $correction, SQLITE3_TEXT);
        $qry->bindValue(':context', $context, SQLITE3_TEXT);
        $qry->bindValue(':source', $source, SQLITE3_TEXT);

        return $qry->execute();

    }


}