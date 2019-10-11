<?php
/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 10:24
 */

namespace Command;


use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Location\Direction;
use Map\Provider\FileMapProvider;
use Map\Provider\RandomMapProvider;
use Map\Render\ConsoleMapRender;
use Map\Player\Chat;
use Map\Player\PlayerInterface;
use Map\Render\MapRenderInterface;
use Map\Render\NCurseRender;
use Map\World\World;
use Psr\Log\LogLevel;
use Snapshot\Instant;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ApplicationCommand extends Command
{
    public function configure()
    {
        $this->setName("application");
        $this->addOption("curse", null, InputOption::VALUE_NONE, "active curse");
        $this->addOption("step-by-step", null, InputOption::VALUE_NONE, "step by step");
        $this->addOption("size", null, InputOption::VALUE_REQUIRED, "taille", exec('tput lines')."x".exec('tput cols'));
        $this->addOption("load-dump", null, InputOption::VALUE_REQUIRED, "memory load dump");
        $this->addOption("map", null, InputOption::VALUE_REQUIRED, "file");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        mb_internal_encoding('UTF-8');

        try {

            list($line, $colonne) = explode("x", $input->getOption("size"));


            $symfonyStyle = new SymfonyStyle($input, $output);
            $symfonyStyle->title("IA");

            $logger = new MultipleLogger();
            $mapRender = (!$input->getOption("curse")) ? new ConsoleMapRender($output, true) : new NCurseRender($line, $colonne, $logger);

            if(!$input->getOption("load-dump"))
            {
                $world = $this->createWorld($input, $mapRender, $logger);
            }
            else
            {
                $file = $input->getOption("load-dump");

                if(!file_exists($file))
                {
                    if(extension_loaded("ncurses") && $mapRender instanceof NCurseRender) ncurses_end();

                    $symfonyStyle->error("le fichier $file n'existe pas");

                    return;
                }

                $world = unserialize(file_get_contents($file))->getFlashMemory()->all()[0]->getData();
            }

            $this->render($mapRender, $world);

            while(1) {
                if ($input->getOption('step-by-step')) {
                    $world->getMemoryManager()->getFlashMemory()->addInstant(
                        new Instant($world)
                    );

                    $world->getLogger()->info(sprintf(
                        'nombre instantané : %d',
                        $world->getMemoryManager()->getFlashMemory()->count()
                    ));

                    while ($world->getInputController()->getKey() != 'n') {
                        $world->getInputController()->update();
                        usleep(200);

                        if($world->getInputController()->getKey() == "x")
                        {
                            $hash = $world->getMemoryManager()->persist();
                            $world->getLogger()->log(LogLevel::INFO, 'persist snapshop memory on hash '.$hash);

                            $this->render($mapRender, $world);
                        }

                        if($world->getInputController()->getKey() == "r")
                        {
                            $world->getLogger()->log(LogLevel::INFO, 'soft reload game');
                            $world = $this->createWorld(
                                $input,
                                $mapRender,
                                $logger
                            );

                            $this->render($mapRender, $world);
                        }

                        if($world->getInputController()->getKey() == "q")
                        {
                            return;
                        }

                        if($world->getInputController()->getKey() == "p")
                        {
                            $world = unserialize($world->getMemoryManager()->getFlashMemory()->all()[0]);
                        }
                    }
                }
                if ($this->update($world)) {
                    $this->render($mapRender, $world);
                }
            }
        }
        catch(\Exception $e)
        {
            if(extension_loaded("ncurses") && $mapRender instanceof NCurseRender) ncurses_end();

            $symfonyStyle->error($e->getMessage());

            return;
        }
    }

    protected function addChat($x, $y)
    {
        $chat = new Chat();
       // $chat->getPosition()->setDirection(new Direction(1, 0));

        $chat->getPosition()->setX($x);

        $chat->getPosition()->setY($y);

        return $chat;
    }

    public function update(World $world) {
        return $world->update();
    }

    public function render(MapRenderInterface $mapRender, World $world)
    {
        $players = $world->getPlayerCollection();

        foreach($players as $player)
        {
            $world->getMap()->setItem($player->getPosition(), "P");
        }

        $mapRender->render($world->getMap()->getMap());

        $mapRender->clear($world->getMap()->getMap());
    }

    /**
     * @param InputInterface $input
     * @param $mapRender
     * @param MultipleLogger $logger
     * @return World
     */
    protected function createWorld(InputInterface $input, $mapRender, MultipleLogger $logger): World
    {
        $sizeMap = $mapRender->getSize();

        $mapProvider = ($input->getOption("map")) ? new FileMapProvider($input->getOption("map")) : new RandomMapProvider($sizeMap["y"], $sizeMap["x"]);

        $map = new MapBuilder($mapProvider->getMap());

        $world = new World($map, array(
            $this->addChat(5, 5),
            $this->addChat(10, 10)
        ), $logger);
        return $world;
    }
}
