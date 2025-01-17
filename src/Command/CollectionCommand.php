<?php

namespace App\Command;
 
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Api\CollectionApi;
use App\Api\CarteApi;
use App\Entity\Collections;
use App\Entity\Carte;
use Symfony\Component\Filesystem\Filesystem;
  
class CollectionCommand extends Command
{
    //Nom de la commande
    protected static $defaultName = 'get:cards:collection';
    private CollectionApi $collectionApi;
    private CarteApi $carteApi;
    private EntityManagerInterface $em;

    public function __construct(HttpClientInterface $client, EntityManagerInterface $em)
    {
        $this->collectionApi = new CollectionApi($client);
        $this->carteApi = new CarteApi($client);
        $this->em = $em;

        parent::__construct();
    }

    protected function configure()
    {
        //Option de la commande : nom de la collection recherché
        //Par defaut à -1 si on ne lui donne pas de valeur dans la commande
        $this->addOption(
            'collection_name',
            null,
            InputOption::VALUE_REQUIRED,
            'Le nom de collections rechercher',
            -1
        )
        //Option de la commande : identifiant de la collection choisi
        //Par defalt à -1 si on ne lui donne pas de valeur dans la commande
        ->addOption(
            'collection_choice',
            null,
            InputOption::VALUE_REQUIRED,
            'Choix de la collection par identifiant',
            -1
        );
    }
  
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $collections = $this->collectionApi->getCollections();
        //Ici, on affiche toutes les collections qui ont pour nom l'argument envoyé par la commande
        if($input->getOption('collection_choice') == -1){

            if($input->getOption('collection_name') == -1){
                $output->writeln('Il faut donner un nom de collection');
            }
            else{
                
                //Recherche des différentes occurrences du nom
                foreach ($collections as $key => $collection){
                    if( strpos($collection["name"],$input->getOption('collection_name'))  !==  false){
                        $output->writeln($key . '- ' . $collection["name"]);
                    }
                }
            }
    
        }
        else{
            $dbCollection = $this->em->getRepository(Collections::class)->findAll();
            $exist = false;
            foreach ( $dbCollection as $collection ){
                if ($collection->getCode() == $collections[$input->getOption('collection_choice')]['code']){
                    $exist = true;
                }
            }

            if($exist === false){
                $collection = new Collections();
                $collection->setCode($collections[$input->getOption('collection_choice')]['code'])
                           ->setDateSortie(new \Datetime($collections[$input->getOption('collection_choice')]['released_at']))
                           ->setNom($collections[$input->getOption('collection_choice')]['name'])
                           ->setSvg($collections[$input->getOption('collection_choice')]['icon_svg_uri'])
                ;
                $this->em->persist($collection);

                $cartes = $this->carteApi->getCartesCollection($collection->getCode());
                foreach ( $cartes as $carte ){
                    $dbCarte = new Carte();
                    $dbCarte->setNom($carte['name'])
                            ->setType($carte['type_line'])
                            ->setCouleur($carte['border_color'])
                            ->setArtiste($carte['artist'])
                            ->setCollection($collection);

                    //L'image qui va être créée dans public/cards
                    $png = strtolower('cards/' . str_replace([' ','-',':',',','/','\''],'',$carte['name']) . '.png');
                    
                    //Gestion du cas ou l'api retour l'image d'une manière différente
                    if(array_key_exists('image_uris',$carte)){
                        $dbCarte->setImage($png);
                        file_put_contents('public/' . $png, file_get_contents($carte['image_uris']['png']));
                    }
                    else{
                        $dbCarte->setImage($png);
                        file_put_contents('public/' . $png, file_get_contents($carte['card_faces'][0]['image_uris']['png']));
                    }

                    //Gestion du cas ou l'api retourne une description d'une autre manière
                    if(array_key_exists('oracle_text',$carte)){
                        $dbCarte->setDescription($carte['oracle_text']);
                    }
                    else{
                        $dbCarte->setDescription($carte['card_faces'][0]['oracle_text']);
                    }

                    $this->em->persist($dbCarte);
                }


                $this->em->flush();
                $output->writeln('La collection et les cartes associer ont été ajouter à la base de données');
            }
            else{
                $output->writeln('La collection existe déjà en base de données');
            }
        }
    }
}