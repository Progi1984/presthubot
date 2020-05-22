<?php


namespace Console\App\Command;

use DateInterval;
use DateTime;
use Console\App\Service\Github;
use Github\ResultPager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GithubCheckIssueCommand extends Command
{
    const LABEL_DUPLICATE = 'label:Duplicate';

    /**
     * @var Github;
     */
    protected $github;

    protected function configure()
    {
        $this->setName('github:check:issue')
            ->setDescription('Check Github Issue')
            ->addOption(
                'ghtoken',
                null,
                InputOption::VALUE_OPTIONAL,
                '',
                $_ENV['GH_TOKEN']
            )
            ->addOption(
                'request',
                null,
                InputOption::VALUE_OPTIONAL
            )
            ->addOption(
                'filter:file',
                null,
                InputOption::VALUE_OPTIONAL
            );

    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->github = new Github($input->getOption('ghtoken'));
        $time = time();

        $date = new DateTime();
        $date->sub(new DateInterval('P1D'));
        $requests = [
            // Check Issue created and closed
            //'Created Issue' => 'is:closed created:>='.$date->format('Y-m-d'),
            'Created Issue' => 'is:issue created:>=2020-03-11',
            // Check Issue duplicated
            'Duplicated Issue' => 'is:closed created:>=2020-03-11 ' . self::LABEL_DUPLICATE,

        ];
        $requestCommon = 'org:PrestaShop is:issue ';

        $request = $input->getOption('request');
        if ($request) {
            if (array_key_exists($request, $requests)) {
                $requests = [
                    $request => $requests[$request]
                ];
            } else {
                $requests = [
                    $request => $request
                ];
            }
        }
        $filterFile = $input->getOption('filter:file');
        $filterFile = explode(',', $filterFile);
        $table = new Table($output);
        $table->setStyle('box');
        $searchApi = $this->github->getClient()->api('search');
        $paginator  = new ResultPager($this->github->getClient());
        foreach($requests as $title => $request) {
            $result = $paginator->fetchAll($searchApi, 'issues', [$requestCommon . $request]);
            $hasRows = $this->checkIssue(
                $title,
                $result,
                $output,
                $table,
                $hasRows ?? false,
                $title == 'PR Waiting for Review' || count($requests) == 1 ? true : false,
                $filterFile
            );
        }

        $table->render();
        $output->writeLn(['', 'Output generated in ' . (time() - $time) . 's.']);
    }

    private function checkIssue(
        string $title,
        array $returnSearch,
        OutputInterface $output,
        Table $table,
        bool $hasRows,
        bool $needCountFilesType,
        array $fileTypeAuth
    ) {
        $rows = [];
        uasort($returnSearch, function($row1, $row2) {
            $repoName1 = strtolower(str_replace('https://api.github.com/repos/PrestaShop/', '', $row1['repository_url']));
            $repoName2 = strtolower(str_replace('https://api.github.com/repos/PrestaShop/', '', $row2['repository_url']));
            $key = 0;
            if ($repoName1 == $repoName2) {
                if ($row1['number'] == $row2['number']) {
                    return 0;
                }
                return $row1['number'] < $row2['number'] ? -1 : 1;
            }
            return $repoName1 < $repoName2 ? -1 : 1;
        });
        foreach($returnSearch as $issueGithub) {
            $linkedIssue = $this->github->getLinkedIssue($issueGithub);
            $repoName = str_replace('https://api.github.com/repos/PrestaShop/', '', $issueGithub['repository_url']);
            $issueGithubTitle = str_split($issueGithub['title'], 70);
            $issueGithubTitle = implode(PHP_EOL, $issueGithubTitle);
            $countFilesTypeTitle = '';
            if ($needCountFilesType) {
                $authFilterFileType = false;
                $countFilesType = $this->github->countPRFileTypes('PrestaShop', $repoName, $issueGithub['number']);
                ksort($countFilesType);
                foreach ($countFilesType as $fileType => $count) {
                    $countFilesTypeTitle .= $fileType . ' (' . $count . ')' . PHP_EOL;
                    if (!empty($fileTypeAuth)) {
                        if (in_array($fileType, $fileTypeAuth)) {
                            $authFilterFileType = true;
                        }
                    }
                }
                $countFilesTypeTitle = substr($countFilesTypeTitle, 0, -1);
            } else {
                $authFilterFileType = true;
            }
            if ($authFilterFileType === false) {
                continue;
            }
            $rows[] = [
                '<href=https://github.com/PrestaShop/'.$repoName.'>'.$repoName.'</>',
                '<href='.$issueGithub['html_url'].'>#'.$issueGithub['number'].'</>',
                $issueGithub['created_at'],
                $issueGithubTitle,
                '<href='.$issueGithub['user']['html_url'].'>'.$issueGithub['user']['login'].'</>',
                !empty($issueGithub['milestone']) ? '    <info>✓</info>' : '    <error>✗ </error>',
                !is_null($linkedIssue) && $repoName == 'PrestaShop'
                    ? (!empty($linkedIssue['milestone']) ? '<info>✓ </info>' : '<error>✗ </error>') .' <href='.$linkedIssue['html_url'].'>#'.$linkedIssue['number'].'</>'
                    : '',
                $countFilesTypeTitle,
            ];
        }
        if (empty($rows)) {
            return $hasRows;
        }
        if ($hasRows) {
            $table->addRows([new TableSeparator()]);
        }
        $title='<fg=black;bg=white;options=bold> ' . $title . ' ('.count($rows).') </>';
        echo $title;
        $table->addRows([
            [new TableCell('<fg=black;bg=white;options=bold> ' . $title . ' ('.count($rows).') </>', ['colspan' => 8])],
            new TableSeparator(),
            [
                '<info>Project</info>',
                '<info>#</info>',
                '<info>Created At</info>',
                '<info>Title</info>',
                '<info>Author</info>',
                '<info>Milestone</info>',
                '<info>Issue</info>',
                '<info>Files</info>',
            ],
            new TableSeparator(),
        ]);
        $table->addRows($rows);
        return true;
    }

}
