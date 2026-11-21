<?php

declare(strict_types=1);

/** Creates a source release archive without runtime secrets or private media. Run on developer machine. */
$root=dirname(__DIR__);$dest=$argv[1]??null;
if (!is_string($dest) || !str_ends_with($dest,'.zip') || !class_exists(ZipArchive::class)) {
    fwrite(STDERR,"Usage: php scripts/build-release.php /absolute/path/release.zip (requires ext-zip)\n");exit(1);
}
$zip=new ZipArchive();if ($zip->open($dest,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) exit(1);
$dirs=['src','public','install','config/editions','modules','themes','docs','scripts','tests','.github','examples'];
foreach($dirs as $dir){
 if(!is_dir($root.'/'.$dir))continue;
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file){
  if($file->isLink()||!$file->isFile())continue;
  $name=substr($file->getPathname(),strlen($root)+1);
  if(str_contains($name,'private')||str_contains('/'.$name.'/','/__pycache__/')
     ||preg_match('/\.(?:pem|key|log|pyc|pyo)$/D',$name))continue;
  $zip->addFile($file->getPathname(),$name);
 }
}
foreach(['README.md','AGENTS.md','composer.json','.gitignore','VERSION'] as $name){if(is_file($root.'/'.$name))$zip->addFile($root.'/'.$name,$name);}
$zip->close();echo "Source release built: $dest\n";
