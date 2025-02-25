<?php declare(strict_types=1);

namespace DDT\Tool;

use DDT\CLI;
use DDT\Docker\DockerContainer;
use DDT\Docker\DockerImage;

class AwsCredsTool extends Tool
{
    private $aws;

    private $stdout;

    public function __construct(CLI $cli, AwsTool $tool)
    {
    	parent::__construct('aws-creds', $cli);
        $this->setToolCommand('run', null, true);

        $this->aws = $tool;
        $this->stdout = $this->cli->getChannel('stdout');
    }
    
    public function getToolMetadata(): array
    {
        return [
            'title' => 'AWS CLI Credential tool',
            'short_description' => 'A tool to output into the shells environment AWS credentials from a chosen profile',
            'description' => [
                'The purpose of this command is to output credentials into the shells environment so they can',
                'be used by commands that run inside that shell after they are set, such as aws cli or terraform, etc.'
            ],
            'examples' => [
                '- export $(ddt aws-creds mock) && aws s3api list-buckets',
                '- export $(ddt aws-creds mock) && aws --endpoint=http://s3.eu-west-1.aws.develop s3api list-buckets',
            ]
        ];
    }

    private function awsCommand(string $command): string
    {
        $this->stdout->tap(true);
        $this->stdout->enable(false);
        $this->aws->run($command);
        $output = $this->stdout->history();
        $this->stdout->enable(true);

        return trim(implode("\n", array_filter($output)));
    }

    public function get(?string $profile=null, ?string $sessionName='default'): ?array
    {
        $list = explode("\n",$this->awsCommand('configure list-profiles'));

        if(empty($profile)){
            $this->cli->stderr("{red}First parameter must be the name of the profile from the config file{end}\n");
            $this->cli->stderr("Available Profiles: \n");
            foreach($list as $p){
                $this->cli->stderr("\t- ".trim($p)."\n");
            }

            return null;
        }else if(!in_array($profile, $list)){
            $this->cli->stderr("AWS Profile '${profile}' was not found, please choose one from the following list and try again\n");
            $this->cli->stderr("Available Profiles: \n");
            foreach($list as $p){
                $this->cli->stderr("\t- ".trim($p)."\n");
            }

            $this->cli->print("AWS_CREDS=failed\n");
            return null;
        }else{
            $sessionName = "awscli_ddt_" . str_replace('-','_',$profile) . "_" . $sessionName;

            $roleArn = $this->awsCommand("configure get role_arn --profile $profile");

            $output = [];

            if(!empty($roleArn)){
                // If there is a role_arn, we can use it to assume-role, extract params and echo them
                $creds = $this->awsCommand("sts assume-role --role-arn $roleArn --profile $profile --role-session-name=$sessionName");
                $creds = json_decode($creds, true);

                $output[] = "AWS_ROLE_ARN=$roleArn";
                $output[] = "AWS_ACCESS_KEY_ID={$creds['Credentials']['AccessKeyId']}";
                $output[] = "AWS_SECRET_ACCESS_KEY={$creds['Credentials']['SecretAccessKey']}";
                $output[] = "AWS_SESSION_TOKEN={$creds['Credentials']['SessionToken']}";
            }else{
                // There was no role_arn, so that means we just extract the key/secret and echo them
                $accessKey = $this->awsCommand("configure get aws_access_key_id --profile $profile");
                $secretAccessKey = $this->awsCommand("configure get aws_secret_access_key --profile $profile");

                $output[] = "AWS_ACCESS_KEY_ID=$accessKey";
                $output[] = "AWS_SECRET_ACCESS_KEY=$secretAccessKey";
            }

            $output[] = "AWS_CREDS=success";
            return $output;
        }
    }

    public function run(?string $profile=null, ?string $sessionName='default'): void
    {
        $creds = $this->get($profile, $sessionName);

        if($creds === null){
            exit(1);
        }

        $this->cli->print(implode("\n", $creds));
    }
}
