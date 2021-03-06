<?php
declare(strict_types = 1);
namespace Browscap\Command;

use Browscap\Helper\LoggerHelper;
use JsonSchema\Constraints;
use JsonSchema\SchemaStorage;
use Localheinz\Json\Normalizer\Validator;
use Seld\JsonLint\JsonParser;
use Seld\JsonLint\ParsingException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class ValidatePlatformsCommand extends Command
{
    /**
     * @var string
     */
    private const DEFAULT_RESOURCES_FOLDER = '/../../../resources';

    protected function configure() : void
    {
        $defaultResourceFolder = __DIR__ . self::DEFAULT_RESOURCES_FOLDER;

        $this
            ->setName('validate-platforms')
            ->setDescription('validates the resource files for the platforms')
            ->addOption('resources', null, InputOption::VALUE_REQUIRED, 'Where the resource files are located', $defaultResourceFolder);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|null null or 0 if everything went fine, or an error code
     */
    protected function execute(InputInterface $input, OutputInterface $output) : ?int
    {
        $loggerHelper = new LoggerHelper();
        $logger       = $loggerHelper->create($output);

        /** @var string $resources */
        $resources = $input->getOption('resources');

        $platformsResourcePath = $resources . '/platforms.json';

        $logger->info('Resource folder: ' . $resources);

        $schemaStorage   = new SchemaStorage();
        $schemaValidator = new Validator\SchemaValidator(
            new \JsonSchema\Validator(
                new Constraints\Factory(
                    $schemaStorage,
                    $schemaStorage->getUriRetriever()
                )
            )
        );

        $schemaUri = 'file://' . realpath(__DIR__ . '/../../../schema/platforms.json');

        try {
            /** @var \stdClass $schema */
            $schema = $schemaStorage->getSchema($schemaUri);
        } catch (\Throwable $exception) {
            $logger->critical('the schema file is invalid');

            return 1;
        }
        $failed = false;

        $jsonParser = new JsonParser();

        $finder = new Finder();
        $finder->files();
        $finder->name($platformsResourcePath);
        $finder->ignoreDotFiles(true);
        $finder->ignoreVCS(true);
        $finder->sortByName();
        $finder->ignoreUnreadableDirs();
        $finder->in($resources);

        foreach ($finder as $file) {
            /* @var \Symfony\Component\Finder\SplFileInfo $file */
            $logger->info('read source file ' . $file->getPathname());

            try {
                $json = $file->getContents();
            } catch (\RuntimeException $e) {
                $logger->critical(new \Exception(sprintf('could not read file "%s"', $file->getPathname()), 0, $e));

                continue;
            }

            try {
                $decoded = $jsonParser->parse($json, JsonParser::DETECT_KEY_CONFLICTS);

                if (!$schemaValidator->isValid($decoded, $schema)) {
                    $logger->critical(sprintf('file "%s" is not valid', $file->getPathname()));
                    $failed = true;
                }
            } catch (ParsingException $e) {
                $logger->critical('File "' . $file->getPathname() . '" had invalid JSON. [JSON error: ' . json_last_error_msg() . ']');
                $failed = true;
            }
        }

        if (!$failed) {
            $output->writeln('the platforms file is valid');
        }

        return (int) $failed;
    }
}
