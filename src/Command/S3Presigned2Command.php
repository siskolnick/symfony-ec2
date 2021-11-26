<?php

namespace App\Command;

use App\Service\S3Service;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Aws\S3\S3Client;
use Symfony\Bundle\MakerBundle\Str;

class S3Presigned2Command extends Command
{
    protected static $defaultName = 'app:s3-presigned2';
    protected static $defaultDescription = 'Test s3 presigned';

    /**
     * @var S3Service
     */
    private $service;

    public function __construct(
        S3Service $service
    ) {
        $this->service = $service;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'File name')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file_name = $input->getArgument('file');

        if ($file_name) {
            $io->note(sprintf('You passed an argument: %s', $file_name));
        }

        $presignedUrl = $this->getLink($file_name);
        $io->success($presignedUrl);
        //file_put_contents('url.txt',$presignedUrl);
        return Command::SUCCESS;
    }

    private function getLink( string $file_name ) {
        /** @var S3Client $s3Client */
        $s3Client = new S3Client([
            'region' => 'us-east-1',
            'version' => '2006-03-01',
        ]);

        $fullName = 'assets/'.$file_name;
        $bucket = 'hopper-rtr-reports';
        $s3Client->putObject([
            'Bucket'     => $bucket,
            'Key'        => $file_name,
            'SourceFile' => $fullName,
        ]);
        
        // wait for the object to be accessible
        $s3Client->waitUntil('ObjectExists',[ 
            'Bucket' => $bucket,
            'Key'    => $file_name
        ]);
    
        $cmd = $s3Client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $file_name
        ]);
    
        $request = $s3Client->createPresignedRequest($cmd, '+20 minutes');
    
        // Get the actual presigned-url
        $presignedUrl = (string)$request->getUri();
        return $presignedUrl;
    }
}
