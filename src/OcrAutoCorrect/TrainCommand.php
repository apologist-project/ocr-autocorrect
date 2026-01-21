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
        'j' =>  'Join at hyphen(s)',
        'x' =>  'Explode at hyphen(s)',
        'k' =>  'Keep as-is',
        'w' =>  'Whitelist',
        'r' =>  'Remove',
        'a' =>  'Autocorrect with 1st suggested value',
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

        $ff = false;
        $correction = $this->getAutoCorrect($error);
        if (is_null($correction)) {

            $options = $this->getOptions($suggestions);
            $selection = $this->out->choice(
                'What do you want to do with this possible error?',
                $options,
                's'
            );

            if ($selection == 's') {
                $this->out->comment('Skipped');
            } else if ($selection == 'q') {
                $this->out->block('Goodbye!');
                exit;
            } else {

                if (in_array($selection, ['k', 'w'])) {
                    $correction = $error;
                } else if ($selection == 'a') {
                    $correction = $options[1];
                } else if ($selection == 'r') {
                    $correction = '';
                } else if ($selection == 'e') {
                    $correction = $this->out->ask("Enter custom value. Submit 'm' to go back to menu.");
                } else if ($selection == 'j') {
                    $correction = str_replace('-', '', $error);
                } else if ($selection == 'x') {
                    $correction = str_replace('-', ' ', $error);
                } else if ($selection == 'c') {
                    $error = '';
                    if (isset($words[$i-1])) {
                        $error .= "{$words[$i-1]} ";
                    }
                    $error .= $words[$i];
                    if (isset($words[$i+1])) {
                        $error .= " {$words[$i+1]}";
                    }
                    $correction = $this->out->ask("What would you like to replace <fg=white;bg=red>{$error}</> with? Submit 'm' to go back to menu.");
                    $ff = true;
                } else {
                    $correction = $options[$selection];
                }

                // Autocorrect if a selection was chosen that will likely hold forever
                $auto = in_array($selection, ['w', 'a', 'j', 'x']);

                // If user selected "undo", recursively call this method to get a new selection
                if ($correction === 'm') {
                    return $this->getReplacement(
                        $error,
                        $suggestions,
                        $words,
                        $i,
                        $context,
                        $file
                    );
                }

                if (!$this->in->getOption('dry-run')) {
                    if (!$this->saveCorrection($error, $correction, $context, $file, $auto)) {
                        $this->out->error("Error saving correction to database");
                    }
                }

                if ($selection == 'k') {
                    $this->out->comment('Kept as-is');
                } else if ($selection == 'w') {
                    $this->out->comment('Whitelisted');
                }

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
        array_splice( $options, count(static::CHOICES), 0, $suggestions);
        unset($options[0]);
        return $options;
    }

    /**
     * @param string $str
     * @return string|null
     */
    public function getAutoCorrect(string $str): string|null
    {

        $qry = $this->db->prepare("
            SELECT
                COUNT(error) AS `count`,
                correction
            FROM
                corrections
            WHERE
                error = :str AND
                auto = 1
            GROUP BY
                correction
            ORDER BY
                `count` DESC,
                correction ASC
        ");
        $qry->bindValue(':str', $str, SQLITE3_TEXT);

        $res = $qry->execute();
        if ($row = $res->fetchArray()) {
            return $row['correction'];
        } else {
            return null;
        }

    }

    /**
     * @param string $error
     * @param string $correction
     * @param string $context
     * @param string $file
     * @return SQLite3Result
     */
    public function saveCorrection(
        string $error,
        string $correction,
        string $context,
        string $file,
        bool $auto
    ): SQLite3Result
    {

        $qry = $this->db->prepare("
            INSERT INTO
                corrections
            (
                error,
                correction,
                context,
                file,
                auto
            )
            VALUES 
            (
                :error,
                :correction,
                :context,
                :file,
                :auto
            )
        ");

        $qry->bindValue(':error', $error, SQLITE3_TEXT);
        $qry->bindValue(':correction', $correction, SQLITE3_TEXT);
        $qry->bindValue(':context', str_replace($error, "[{$error}]", $context), SQLITE3_TEXT);
        $qry->bindValue(':file', $file, SQLITE3_TEXT);
        $qry->bindValue(':auto', $auto, SQLITE3_INTEGER);

        return $qry->execute();

    }


}