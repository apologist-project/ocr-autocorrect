<?php

namespace OcrAutoCorrect;

use SQLite3;
use SQLite3Result;

class OcrAutoCorrect
{

    const DB_FILE = './corrections.db';
    static $dbFile = null;
    static $db = null;
    static $lang = null;

    public static function init(string $dbFile = null, string $lang = 'en')
    {
        static::$lang = $lang;
        if (is_null($dbFile)) {
            $dbFile = static::DB_FILE;
        }
        static::$dbFile = $dbFile;
        static::createDb();
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

        $str = strtolower($str);
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
            echo "{$row['correction']}: {$row['count']}\n";
        }

        // Add suggestions from PSPELL
        $pspell = pspell_new(static::$lang);
        foreach (pspell_suggest($pspell, $str) as $suggestion) {
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

}