<?php declare(strict_types=1);

namespace DDT\Services;

use DDT\CLI;
use DDT\CLI\Output\DockerFilterChannel;
use DDT\CLI\Output\StringChannel;
use DDT\Config\DockerConfig;
use DDT\Contract\ChannelInterface;
use DDT\Docker\DockerRunProfile;
use DDT\Exceptions\Docker\DockerException;
use DDT\Exceptions\Docker\DockerInspectException;
use DDT\Exceptions\Docker\DockerMissingException;
use DDT\Exceptions\Docker\DockerNotRunningException;

class DockerService
{
    /** @var CLI */
    private $cli;

    /** @var DockerConfig */
    private $config;

	private $profile;
	private $exitCode = 0;
    private $command = 'docker';

    const DOCKER_NOT_RUNNING = "The docker daemon is not running";
	const DOCKER_PORT_ALREADY_IN_USE = "Something is already using port '{port}' on this machine, please stop that service and try again";
	const DOCKER_NETWORK_ALREADY_ATTACHED = "/endpoint with name (?<container>[^\s].*) already exists in network (?<network>[^\s].*)/";

	private function parseErrors(string $message): void
	{
		if(DockerNotRunningException::match($message)){
			throw new DockerNotRunningException();
		}
	}

	private function isError(string $message, string $pattern): bool
	{
		return !!preg_match($pattern, $message, $matches);
	}

    public function __construct(CLI $cli, DockerConfig $config)
	{
        $this->cli = $cli;

        $this->setConfig($config);

        // Default empty profile that uses the machines local docker installation
        $this->setProfile(new DockerRunProfile('default'));

        if($this->cli->isCommand('docker') === false){
            throw new DockerMissingException();
        }
    }

	public function getVersion(): array
    {
        return json_decode($this->exec('version --format "{{json .Client.Version}}"'), true);
    }

    public function setConfig(DockerConfig $config): void
    {
        $this->config = $config;
    }

    public function getConfig(): DockerConfig
    {
        return $this->config;
    }

	public function listProfile(): array
	{
		return $this->config->listProfile();
	}

    public function setProfile(DockerRunProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function pull(string $image): int
    {
        try{
			return $this->passthru("pull $image");
		}catch(\Exception $e){
			$this->cli->print("{red}" . $this->parseErrors($e->getMessage()) . "{end}");
		}

		return 1;
    }

	public function toCommandLine(string $command): string
	{
		return implode(' ', array_filter([$this->command, $this->profile->toCommandLine(), $command]));
	}

	public function getExitCode(): int
	{
		return $this->exitCode;
	}

	public function exec(string $command, ?ChannelInterface $stdout=null, ?ChannelInterface $stderr=null)
	{
		$command = $this->toCommandLine($command);

		$stdout = $stdout ?? new StringChannel();
		$stderr = $stderr ?? new StringChannel();
		$filter = new DockerFilterChannel($stderr);
		$output = $this->cli->exec($command, $stdout, $filter);

		$this->parseErrors($output);

		$this->exitCode = $this->cli->getExitCode();
		
		return $output;
	}

	public function passthru(string $command): int
	{
		$stdout = $this->cli->getChannel('stdout');
		$stderr = $this->cli->getChannel('stderr');
		
		$this->exec($command, $stdout, $stderr);

		return $this->getExitCode();
	}

	public function stop(string $containerId): bool 
	{
		try{
			$this->exec("kill $containerId 1>&2");

			return true;
		}catch(\Exception $e){
			$this->cli->print("{red}".$this->parseErrors($e->getMessage())."{end}\n");
			return false;
		}
	}

	public function delete(string $containerId, ?bool $silent=false): bool
	{
		try{
			$this->exec("container rm $containerId 1>&2");

			return true;
		}catch(\Exception $e){
			if($silent === false){
				$this->cli->print("{red}".$this->parseErrors($e->getMessage())."{end}\n");
			}
			return false;
		}
	}

	public function pruneContainer(): void
	{
		$this->exec("container prune -f &>/dev/null");
	}

	/**
	 * TODO: I don't think this function is useful anymore
	 */
    public function deleteContainer(string $container): bool
	{
		return $this->stop($container) && $this->delete($container);
	}

    /**
     * @param $type
     * @param $name
     * @return array|null
     * @throws DockerInspectException
     */
	public function inspect(string $type, string $name, ?string $filter='{{json . }}'): ?array
	{
		$result = $this->exec("$type inspect $name -f '$filter'");
			
		if($this->getExitCode() !== 0) {
			throw new DockerInspectException($type, $name);
		}

		try{
			// attempt to decode the result, it might fail cause some return values are not valid json
			$r = json_decode($result, true);
			// if empty, then assume decoding it failed, revert back to original value
			if(empty($r) && !is_array($r)) $r = $result;
			// if 'r' is scalar, wrap it in an array, so this function has a predictable return value
			if(is_scalar($r)) $r = [$r];

			return $r;
		}catch(\Exception $e){
			throw new DockerInspectException($type, $name, 0, $e);   
		}
	}

	public function logsFollow(string $containerId, bool $follow, ?string $since=null): int
	{
		if(!empty($since)) $since = "--since=$since";

		$follow = $follow ? 'logs -f' : 'logs';

		return $this->passthru("$follow $containerId $since");
	}
}