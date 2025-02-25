<?php
namespace DDT\Exceptions\Filesystem;

class DirectoryExistsException extends \Exception
{
    public function __construct(string $path, int $code = 0, \Throwable $previous = null)
    {
        parent::__construct("The directory '$path' already exists", $code, $previous);
    }
};
