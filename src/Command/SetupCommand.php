<?php

namespace Drutiny\SumoLogic\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Drutiny\SumoLogic\Audit\ApiEnabledAudit;

class SetupCommand extends Command {

  /**
   * @inheritdoc
   */
  protected function configure() {
    $this
      ->setName('setup:sumologic')
      ->setDescription('Add API credentials for Drutiny to talk to Sumologic over.');
  }

  /**
   * @inheritdoc
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $io = new SymfonyStyle($input, $output);
    $helper = $this->getHelper('question');

    $data = [];

    // Title.
    $question = new Question('access_id: ');
    $data['access_id'] = $helper->ask($input, $output, $question);

    // Name.
    $question = new Question('access_key: ');
    $data['access_key'] = $helper->ask($input, $output, $question);

    $filepath = ApiEnabledAudit::credentialFilepath();
    $dir = dirname($filepath);

    if (!file_exists($dir) && !mkdir($dir, 0744, TRUE)) {
      $io->error("Could not create $dir");
      return FALSE;
    }

    file_put_contents($filepath, json_encode($data));
    $io->success("Credentials written to $filepath.");
  }
}
 ?>
