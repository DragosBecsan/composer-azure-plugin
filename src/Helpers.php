<?php

namespace MarvinCaspar\Composer;

/**
 * 
 */
class Helpers
{
    /**
     * Compress the provided directory to a zip archive
     */
    public static function buildArchive(string $root_path)
    {
        $root_path = realpath($root_path);
        $archive = new \ZipArchive();
        $filename = $root_path . '.zip';

        if($archive->open($filename, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) 
        {
            exit("Unable to open file <$filename>\n");
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root_path), \RecursiveIteratorIterator::LEAVES_ONLY);
        
        foreach($files as $name => $file)
        {
            if(!$file->isDir())
            {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($root_path) + 1);

                $archive->addFile($file_path, $relative_path);
            }
        }

        $archive->close();
    }

    /**
     * Recursively delete a directory
     */
    public static function removeDirectory(string $root_path)
    {
        $dir = opendir($root_path);
        
        while(false !== ($file = readdir($dir)))
        {
            if(($file != '.') && ($file != '..'))
            {
                $full = $root_path . '/' . $file;
                
                if(is_dir($full))
                {
                    self::removeDirectory($full);
                }
                else
                {
                    unlink($full);
                }
            }
        }

        closedir($dir);
        rmdir($root_path);
    }

    /**
     * Recursively copy a directory
     */
    public static function copyDirectory(string $src, string $dst)
    { 
        $dir = opendir($src);
        @mkdir($dst);
        
        while(false !== ($file = readdir($dir)))
        {
            if(($file != '.') && ($file != '..'))
            {
                if(is_dir($src . '/' . $file))
                {
                    self::copyDirectory($src . '/' . $file, $dst . '/' . $file);
                }
                else
                {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }

        closedir($dir);
    } 
}