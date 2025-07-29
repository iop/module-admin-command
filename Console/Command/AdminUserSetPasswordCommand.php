<?php

declare(strict_types=1);

namespace Iop\Admin\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\DataObjectFactory as DataValidatorFactory;
use Magento\Setup\Console\Command\AbstractSetupCommand;
use Magento\Setup\Model\AdminAccount;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use Magento\User\Model\ResourceModel\UserFactory as UserResourceFactory;
use Magento\User\Model\UserValidationRules;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * CLI command to forcefully set the password for an existing admin user.
 */
class AdminUserSetPasswordCommand extends AbstractSetupCommand
{
    /**
     * @param UserValidationRules $validationRules
     * @param UserFactory $userFactory
     * @param UserResourceFactory $userResourceFactory
     * @param State $appState
     * @param DataObjectFactory $dataObjectFactory
     * @param DataValidatorFactory $dataValidatorFactory
     */
    public function __construct(
        private readonly UserValidationRules $validationRules,
        private readonly UserFactory $userFactory,
        private readonly UserResourceFactory $userResourceFactory,
        private readonly State $appState,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly DataValidatorFactory $dataValidatorFactory,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('iop:admin:user:set-password')
            ->setDescription('Forcefully sets the password of an admin user')
            ->setDefinition($this->getOptionsList());
        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        if (!$input->getOption(AdminAccount::KEY_USER)) {
            $question = new Question('<question>Admin user:</question> ');
            $this->addNotEmptyValidator($question);
            $input->setOption(AdminAccount::KEY_USER, $questionHelper->ask($input, $output, $question));
        }

        if (!$input->getOption(AdminAccount::KEY_PASSWORD)) {
            if (!$this->userExists((string) $input->getOption(AdminAccount::KEY_USER))) {
                // Silently return. The execute() method will provide the final, clean error message.
                return;
            }

            $question = new Question('<question>Admin password:</question> ');
            $question->setHidden(true);
            $question->setValidator(function (?string $value): string {
                if (empty(trim((string)$value))) {
                    throw new \InvalidArgumentException('A password is required.');
                }
                // If not empty, proceed to check complexity rules
                return $this->validatePasswordForPrompt($value);
            });
            $input->setOption(AdminAccount::KEY_PASSWORD, $questionHelper->ask($input, $output, $question));
        }
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Area code is already set, which is expected in some environments.
        }

        $errors = $this->findValidationErrors($input);
        if (!empty($errors)) {
            $output->writeln("<error>" . implode(PHP_EOL, $errors) . "</error>");
            return Cli::RETURN_FAILURE;
        }

        $user = $this->getUserByUserName((string) $input->getOption(AdminAccount::KEY_USER));

        try {
            $user->setPassword((string) $input->getOption(AdminAccount::KEY_PASSWORD));
            $user->setForceNewPassword(true);
            $userResource = $this->userResourceFactory->create();
            $userResource->save($user);

            $output->writeln('<info>Password successfully set for user: ' . $user->getUserName() . '</info>');
            return Command::SUCCESS;
        } catch (\Exception $exception) {
            $output->writeln('<error>Failed to set new password: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Adds a not-empty validator to a Question object.
     *
     * @param Question $question The question to add the validator to.
     * @return void
     */
    private function addNotEmptyValidator(Question $question): void
    {
        $question->setValidator(function (?string $value): string {
            if (empty(trim((string)$value))) {
                throw new \InvalidArgumentException('The value cannot be empty.');
            }
            return (string)$value;
        });
    }

    /**
     * Validates a password for a Symfony Question prompt.
     * On success, it returns the password. On failure, it throws an exception.
     *
     * @param string $password The password to validate.
     * @return string The validated password.
     * @throws \InvalidArgumentException If the password does not meet validation rules.
     */
    private function validatePasswordForPrompt(string $password): string
    {
        $passwordErrors = $this->getPasswordValidationErrors($password);
        if (!empty($passwordErrors)) {
            throw new \InvalidArgumentException(implode(PHP_EOL, $passwordErrors));
        }
        return $password;
    }

    /**
     * Returns the list of CLI input options.
     *
     * @return InputOption[]
     */
    private function getOptionsList(): array
    {
        return [
            new InputOption(
                name: AdminAccount::KEY_USER,
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: '(Required) Admin username'
            ),
            new InputOption(
                name: AdminAccount::KEY_PASSWORD,
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: '(Required) Admin password'
            ),
        ];
    }

    /**
     * Sequentially validates all inputs and returns an array of the first errors found.
     *
     * @param InputInterface $input The console input instance.
     * @return string[] An array of error messages, or an empty array if all validation passes.
     */
    private function findValidationErrors(InputInterface $input): array
    {
        $userName = (string) $input->getOption(AdminAccount::KEY_USER);
        if (empty(trim($userName))) {
            return ['Admin username is required.'];
        }
        if (!$this->userExists($userName)) {
            return ['Admin user was not found.'];
        }

        $password = (string) ($input->getOption(AdminAccount::KEY_PASSWORD) ?? '');
        if (empty(trim($password))) {
            return ['Admin password is required.'];
        }

        $passwordErrors = $this->getPasswordValidationErrors($password);
        if (!empty($passwordErrors)) {
            return $passwordErrors;
        }

        return [];
    }

    /**
     * Checks if a user exists by their username.
     *
     * @param string $username The username to check.
     * @return bool True if the user exists, false otherwise.
     */
    private function userExists(string $username): bool
    {
        return $this->getUserByUserName($username) !== null;
    }

    /**
     * Checks a password against Magento validation rules.
     *
     * @param string $password The password to check.
     * @return string[] An array of error messages, or an empty array if valid.
     */
    private function getPasswordValidationErrors(string $password): array
    {
        $dataObject = $this->dataObjectFactory->create(['data' => ['password' => $password]]);
        $dataValidator = $this->dataValidatorFactory->create();
        $this->validationRules->addPasswordRules($dataValidator);

        return !$dataValidator->isValid($dataObject) ? $dataValidator->getMessages() : [];
    }

    /**
     * Returns a user model by username, or null if not found.
     *
     * @param string $userName The username to load.
     * @return User|null The user object or null if not found.
     */
    private function getUserByUserName(string $userName): ?User
    {
        $user = $this->userFactory->create()->loadByUsername($userName);
        return $user->getId() ? $user : null;
    }
}
