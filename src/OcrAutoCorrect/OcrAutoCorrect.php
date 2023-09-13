<?php

namespace OcrAutoCorrect;

use SQLite3;
use SQLite3Result;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class OcrAutoCorrect
{

    const DB_FILE = './corrections.db';
    const CHOICES = [
        's' =>  'Skip',
        'q' =>  'Quit',
        'e' =>  'Enter custom value',
        'c' =>  'Correct expanded context',
        'k' =>  'Keep as-is',
    ];
    static $dbFile = null;
    static $db = null;
    static $dict = null;
    static $lang = null;
    static $input = null;
    static $output = null;

    /**
     * @param string|null $dbFile
     * @param string $lang
     * @return void
     */
    public static function init(string $dbFile = null, string $lang = 'en', int $dictThreshold = 3): void
    {

        static::$lang = $lang;
        if (is_null($dbFile)) {
            $dbFile = static::DB_FILE;
        }
        static::$dbFile = $dbFile;
        static::createDb();
        static::initDict($dictThreshold);





    }

    protected static function initDict(int $dictThreshold): void
    {

        $config = pspell_config_create(static::$lang);
        pspell_config_mode($config, PSPELL_FAST);
        static::$dict = pspell_new_config($config);

        $qry = static::$db->prepare("
            SELECT
                COUNT(error) AS `count`,
                correction
            FROM
                corrections
            GROUP BY
                correction
            HAVING
                `count` >= :min_count
            ORDER BY
                correction ASC
        ");
        $qry->bindValue(':min_count', $dictThreshold, SQLITE3_INTEGER);
        $res = $qry->execute();

        while ($row = $res->fetchArray()) {
            pspell_add_to_session(static::$dict, $row['correction']);
        }

    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    public static function setIO(InputInterface $input, OutputInterface $output): void
    {
        static::$input = $input;
        static::$output = $output;
    }

    /**
     * @return void
     */
    public static function createDb(): void {
        static::$db = new SQLite3(static::$dbFile);
        $res = static::$db->query("
            CREATE TABLE IF NOT EXISTS 
                corrections 
            (
                id INTEGER PRIMARY KEY, 
                error TEXT,
                correction TEXT,
                context TEXT,
                `source` TEXT
            )
        ");
        $res = static::$db->query("
            CREATE INDEX IF NOT EXISTS
                corrections_correction_idx
            ON
                corrections (correction)
        ");
        $res = static::$db->query("
            CREATE INDEX IF NOT EXISTS
                corrections_error_idx
            ON
                corrections (error)
        ");
    }

    /**
     * @return void
     */
    public static function resetDb(): void {
        unlink(static::$dbFile);
        static::createDb();
    }

    /**
     * @param string $str
     * @return array
     */
    public static function getSuggestions(string $str): array
    {

        $qry = static::$db->prepare("
            SELECT
                COUNT(error) AS `count`,
                correction
            FROM
                corrections
            WHERE
                error = :str
            GROUP BY
                correction
            ORDER BY
                `count` DESC,
                correction ASC
        ");
        $qry->bindValue(':str', $str, SQLITE3_TEXT);
        $res = $qry->execute();

        // Populate suggestions from most common existing corrections
        $suggestions = [];
        while ($row = $res->fetchArray()) {
            $suggestions[] = $row['correction'];
        }

        // Add suggestions from PSPELL
        foreach (pspell_suggest(static::$dict, $str) as $suggestion) {
            if (!in_array($suggestion, $suggestions)) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;

    }

    /**
     * @param string $error
     * @param string $correction
     * @param string $context
     * @param string $source
     * @return SQLite3Result
     */
    public static function saveCorrection(string $error, string $correction, string $context, string $source): SQLite3Result
    {

        $qry = static::$db->prepare("
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

    /**
     * @param string $path
     * @return int
     */
    public static function train(string $path): int
    {
        static::processFiles($path, true);
        return Command::SUCCESS;
    }

    /**
     * @param string $path
     * @return int
     */
    public static function correct(string $path): int
    {
        static::processFiles($path);
        return Command::SUCCESS;
    }

    /**
     * @param string $path
     * @param bool $train
     * @return void
     */
    protected static function processFiles(string $path, bool $train = false): void
    {

        static::init();
        $files = static::getFiles($path);
        $io = new SymfonyStyle(static::$input, static::$output);
        foreach ($files as $file)
        {

            $file = realpath($file);
            $io->title($file);

            $content = file_get_contents($file);
            $io->write($content);
            $words = static::parseWords($content);
            $numWords = count($words);
            $errors = [];
            $replacements = [];

            for ($i = 1; $i < $numWords; ++$i) {

                $word = trim($words[$i], '.,"\'-;:');
                if (
                    !preg_match('/([a-zA-Z][\.])+/', $word) && // Don't flag acronyms
                    !filter_var($word, FILTER_VALIDATE_URL) && // Don't flag URLs
                    !pspell_check(static::$dict, $word)
                ) {

                    $io->section($word);

                    $context = '';
                    for ($n = $i-5; $n < $i; ++$n)
                    {
                        if (isset($words[$n])) {
                            $context .= "{$words[$n]} ";
                        }
                    }
                    $context .= str_replace($word, "<fg=white;bg=red>{$word}</>", $words[$i]);
                    for ($n = $i+1; $n < $i+6; ++$n)
                    {
                        if (isset($words[$n])) {
                            $context .= " {$words[$n]}";
                        }
                    }
                    $io->write($context);

                    $options = static::CHOICES;
                    $suggestions = static::getSuggestions($word);
                    array_unshift($suggestions, 'dummy');
                    array_splice( $options, 5, 0, $suggestions);
                    unset($options[0]);

                    $selection = $io->choice(
                        'What do you want to do with this possible error?',
                        $options,
                        's'
                    );

                    if ($selection == 's') {
                        $io->comment('Skipped');
                    } else if ($selection == 'q') {
                        $io->block('Goodbye!');
                        exit;
                    } else {

                        if ($selection == 'k') {
                            $correction = $word;
                        } else if ($selection == 'e') {
                            $correction = $io->ask('Enter custom value');
                        } else if ($selection == 'c') {
                            $word = '';
                            if (isset($words[$i-1])) {
                                $word .= "{$words[$i-1]} ";
                            }
                            $word .= $words[$i];
                            if (isset($words[$i+1])) {
                                $word .= " {$words[$i+1]}";
                            }
                            $correction = $io->ask("What would you like to replace <fg=white;bg=red>{$word}</> with?");
                            ++$i;
                        } else {
                            $correction = $options[$selection];
                        }

                        if (!static::saveCorrection($word, $correction, $context, $file)) {
                            $io->error("Error saving correction to database");
                        }

                        if ($selection == 'k') {
                            $io->comment('Kept as-is');
                        } else {
                            $pos = strpos($content, $word);
                            if ($pos !== false) {
                                $content = substr_replace($content, $correction, $pos, strlen($word));
                            }

                            if (file_put_contents($file, $content)) {
                                $io->text("Replaced <fg=red>{$word}</> with <fg=green>{$correction}</>");
                            } else {
                                $io->error("Error writing to {$file}");
                            }
                        }

                    }

                }

            }

        }
    }

    /**
     * @param string $path
     * @return array
     */
    protected static function getFiles(string $path): array
    {
        $files = [];
        if (is_dir($path)) {
            foreach (glob("{$path}/*.txt") as $file) {
                $files[] = $file;
            }
        } else {
            $files[] = $path;
        }
        return $files;
    }

    /**
     * @param string $content
     * @return array
     */
    protected static function parseWords(string $content): array
    {
        return array_filter(preg_split('/[[:blank:]]+/', preg_replace('/[^[:alpha:][:blank:][:punct:]]/', '', $content)));
    }

}