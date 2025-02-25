<?php declare(strict_types=1);

namespace DDT\Config\External;

use DDT\Contract\External\ProjectConfigInterface;

class StandardProjectConfig extends AbstractProjectConfig implements ProjectConfigInterface
{
	const defaultFilename = 'ddt-project.json';

	public function __construct(string $filename, string $project, ?string $group=null)
	{
		parent::__construct($filename, $project, $group);
	}

	public function getDefaultFilename(): string
    {
        return self::defaultFilename;
    }
}