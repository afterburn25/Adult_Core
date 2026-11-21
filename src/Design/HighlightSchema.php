<?php

declare(strict_types=1);

namespace AdultCore\Design;

use PDO;
use RuntimeException;

/**
 * Three text-only homepage information cards; never member entitlements,
 * executable templates, external images, or private media publication.
 */
final class HighlightSchema
{
    public const VERSION='m18_home_highlights_v1';

    /** @return list<array{title:string,body:string}> */
    public static function defaults(): array
    {
        return [
            ['title'=>'Your space',
             'body'=>'A thoughtfully designed home for your community.'],
            ['title'=>'Member accounts',
             'body'=>'Dedicated account access with privacy-first safeguards.'],
            ['title'=>'Made personal',
             'body'=>'Pages and styling curated by your site administrator.'],
        ];
    }

    /** @return list<array{title:string,body:string}> */
    public static function validate(mixed $items): array
    {
        if (!is_array($items) || array_keys($items)!==[0,1,2]) {
            throw new RuntimeException('Exactly three homepage information cards are required.');
        }
        $result=[];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['title']??null)
                || !is_string($item['body']??null)) {
                throw new RuntimeException('Invalid homepage card text.');
            }
            $title=trim($item['title']);
            $body=trim($item['body']);
            if ($title==='' || $body==='' || strlen($title)>80 || strlen($body)>240
                || preg_match('//u',$title)!==1 || preg_match('//u',$body)!==1
                || preg_match('/[\x00-\x1f\x7f]/',$title)===1
                || preg_match('/[\x00-\x1f\x7f]/',$body)===1) {
                throw new RuntimeException('Homepage card text is empty, too long or contains invalid characters.');
            }
            // HTML markup remains inert plain text; the public renderer escapes it.
            $result[]=['title'=>$title,'body'=>$body];
        }
        return $result;
    }

    public static function installed(PDO $pdo): bool
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([self::VERSION]);
        return (int)$q->fetchColumn()===1;
    }

    public static function migrate(PDO $pdo): void
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([DesignSchema::VERSION]);
        if ((int)$q->fetchColumn()!==1) {
            throw new RuntimeException('Apply the design schema before homepage cards.');
        }
        $check=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_design_settings'
                AND COLUMN_NAME=?");
        $defaults=self::defaults();
        foreach ($defaults as $index=>$values) {
            $number=$index+1;
            foreach (['title'=>80,'body'=>240] as $type=>$limit) {
                $column='highlight_'.$number.'_'.$type;
                $check->execute([$column]);
                if ((int)$check->fetchColumn()===0) {
                    $pdo->exec("ALTER TABLE ac_design_settings
                        ADD COLUMN {$column} VARCHAR({$limit}) NOT NULL DEFAULT ''");
                }
                $s=$pdo->prepare("UPDATE ac_design_settings
                    SET {$column}=? WHERE {$column}=''");
                $s->execute([$values[$type]]);
            }
        }
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
