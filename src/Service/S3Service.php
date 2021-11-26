<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use Aws\Sts\StsClient;
use Exception;

/**
 * This is created originally for RTR reports saved in S3. Class can be reused to put files in S3,
 * and generate a public URL link to download those files.
 * Set the name of the Bucket before any operations
 */
class S3Service
{
    /**
     * Container Interface
     *
     * @var ContainerInterface $container
     */
    private $container;

    /**
     * S3Client
     *
     * @var S3Client $client
     */
    private $client;

    /**
     * Logger
     *
     * @var LoggerInterface $logger
     */
    private $logger;

    /**
     * Bucket for operations
     *
     * @var string $bucketName
     */
    private $bucketName;

    /**
     * Error on SDK calls
     *
     * @var string
     */
    private $error;

    /**
     * Autowired Constructor
     * 
     * @param ContainerInterface $container
     * @param S3Client $client
     * @param LoggerInterface $logger
     */
    public function __construct(ContainerInterface $container,S3Client $client, LoggerInterface $logger){
        $this->container = $container;
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * In case you need to use IAM role in EC2 or other AWS Resource
     * you need to presign objects assuming that role and get an STS token
     *
     * @param string $region
     * @param string $arn
     * @param string $session_name
     * @return void
     */
    public function setStsClient(string $region, string $ARN, string $session_name){
        $this->error = '';
        try {
            $stsClient = new StsClient([
                //'profile' => 'default',
                'version' => '2011-06-15',
                'region' => $region
            ]);
            
            $result = $stsClient->AssumeRole([
                'RoleArn' => $ARN,
                'RoleSessionName' => $session_name,
            ]);

            $this->client = new S3Client([
                'version'     => '2006-03-01',
                'region'      => $region,
                'credentials' =>  [
                    'key'    => $result['Credentials']['AccessKeyId'],
                    'secret' => $result['Credentials']['SecretAccessKey'],
                    'token'  => $result['Credentials']['SessionToken']
                ]
            ]);
        }catch(Exception $e){
            $this->error = "Error creating STS-".$e->getCode().': '.$e->getMessage().' '.$e->getTraceAsString();
            $this->logger->critical($this->error);
        }
    }

    /**
     * Store a file saved in the system to a specific S3 bucket
     *
     * @param string $file_name Name of the file to be saved in S3 (should be unique)
     * @param string $file_path Path needs a backslash / at the end
     * @param bool $envFolder   If true, then add a specific environment folder (dev/myfile.xslx, prod/myfile.xslx)
     * @return string $key  Key in S3
     * @throws Exception
     */
    public function putFile($file_name, $file_path, $envFolder = false ){
        $this->checkBucketSet();
        $this->error = '';
        $key = $file_name;
        $fullName = $file_path.$file_name;
        if( file_exists($fullName) )
        {
            try{
                if( $envFolder )
                    $key = $this->getFolder().$file_name;
    
                $this->logger->info('Putting a file: '.$key.' in bucket: '.$this->bucketName );
                $this->client->putObject([
                    'Bucket'     => $this->bucketName,
                    'Key'        => $key,
                    'SourceFile' => $fullName,
                ]);
                
                // wait for the object to be accessible
                $this->client->waitUntil('ObjectExists',[ 
                    'Bucket' => $this->bucketName,
                    'Key'    => $key
                ]);
                
                $this->logger->info('File: '.$key.' stored');
    
            }catch(Exception $e){
                $this->error = $e->getCode().': '.$e->getMessage().' '.$e->getTraceAsString();
                $this->logger->critical('Exception saving file: '.$key.' in S3. Error: '.$this->error);
            }
        }else{
            $key = '';
            $this->error = 'File for S3 upload not found';
            $this->logger->critical($this->error);
        }

        return $key;
    }

    /**
     * Store an object in S3 and get presigned URL for download
     *
     * @param string $file_name Name of the file to be saved in S3 (should be unique)
     * @param string $file_path Path needs a backslash / at the end
     * @param boolean $envFolder   If true, then add a specific environment folder (dev/myfile.xslx, prod/myfile.xslx)
     * @param int $link_duration   A number that define Link expiration in hours
     * @return string
     */
    public function getPresignedFileURL(string $file_name,string $file_path, bool $envFolder = false, int $link_duration = 72){
        
        // store and get the key
        $key = $this->putFile($file_name, $file_path, $envFolder);
        $presigned = '';
        if( !empty($key) && empty($this->error) ){
            // return URL from S3
            $presigned = $this->getPresignedURL($key, intval($link_duration));
        }
        
        return $presigned;
    }

    /**
     * Store an object in S3 and get presigned URL, asumming role 
     * and using STS client for download
     *
     * @param string $file_name Name of the file to be saved in S3 (should be unique)
     * @param string $file_path Path needs a backslash / at the end
     * @param boolean $envFolder   If true, then add a specific environment folder (dev/myfile.xslx, prod/myfile.xslx)
     * @param int $link_duration   A number that define Link expiration in hours
     * @return string
     */
    public function getPresignedFileWithSTS(
        string $file_name,
        string $file_path,
        string $region, 
        string $ARN,
        string $session_name,
        bool $envFolder = false, 
        int $link_duration = 36
    ){
        
        // store and get the key
        $key = $this->putFile($file_name, $file_path, $envFolder);
        $presigned = '';
        if( !empty($key) && empty($this->error) ){
            // return URL from S3
            $this->setStsClient($region,$ARN,$session_name);
            if( intval($link_duration) > 36 )
                $link_duration = 36;
            $presigned = $this->getPresignedURL($key, intval($link_duration));
        }
        
        return $presigned;
    }

    /**
     * Get a presigned URL for a specific key (object) within a specific S3 bucket
     *
     * @param string $key   Object name that's going to be presigned
     * @param int $link_duration A number that define Link expiration in hours
     * @return string
     * @throws Exception
     */
    public function getPresignedURL(string $key, int $link_duration = 72){
        $this->checkBucketSet();
        $this->error = '';
        $presignedUrl = '';
        try {
            $this->logger->info('Getting presigned URL for: '.$key);
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucketName,
                'Key' => $key
            ]);
        
            $request = $this->client->createPresignedRequest($cmd, '+'.$link_duration.' hours');
            $presignedUrl = (string)$request->getUri();
            $this->logger->info('Presigned URL created: '.$presignedUrl);
        }catch(Exception $e){
            $this->error = $e->getCode().': '.$e->getMessage().' '.$e->getTraceAsString();
            $this->logger->critical('Exception in presigned URL, key: '.$key.'. Error: '.$this->error);
        }
        return $presignedUrl;
    }

    /**
     * Set Bucket Name
     *
     * @param string $bucket
     * @return void
     */
    public function setBucket(string $bucket){
        $this->bucketName = $bucket;
    }

    /**
     * Get last error.
     *
     * @return string
     */
    public function getError(){
        return $this->error;
    }
    
    /**
     * Return the folder where file is going to be stored for the environment
     *
     * @return string $folder
     */
    public function getFolder(){
        $folder = 'dev'; //$this->container->getParameter('APP_ENV');
        $folder = $folder.'/'.date("Y/m/d/");
        return $folder;
    }

    /**
     * Check if BucketName is set
     * 
     * @throws Exception
     * @return void
     */
    public function checkBucketSet(){
        if( empty($this->bucketName) ){
            throw new Exception("Bucket name is not set",500);
        }
    }
}