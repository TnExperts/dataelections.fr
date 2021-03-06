<?php

/*
 * Copyright 2015 Guillaume Royer
 *
 * This file is part of DataElections.
 *
 * DataElections is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 *
 * DataElections is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more
 * details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with DataElections. If not, see <http://www.gnu.org/licenses/>.
 */

namespace AppBundle\Repository;

use AppBundle\Domain\Election\Entity\Candidat\Candidat;
use AppBundle\Domain\Election\Entity\Candidat\Specification\CandidatNuanceSpecification;
use AppBundle\Domain\Election\Entity\Echeance\Echeance;
use AppBundle\Domain\Election\Entity\Election\Election;
use AppBundle\Domain\Election\Entity\Election\ElectionRepositoryInterface;
use AppBundle\Domain\Election\Entity\Election\UniqueConstraintViolationException;
use AppBundle\Domain\Election\VO\Score;
use AppBundle\Domain\Election\VO\VoteInfo;
use AppBundle\Domain\Territoire\Entity\Territoire\AbstractTerritoire;
use AppBundle\Domain\Territoire\Entity\Territoire\CirconscriptionEuropeenne;
use AppBundle\Domain\Territoire\Entity\Territoire\Commune;
use AppBundle\Domain\Territoire\Entity\Territoire\Departement;
use AppBundle\Domain\Territoire\Entity\Territoire\Pays;
use AppBundle\Domain\Territoire\Entity\Territoire\Region;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException as DoctrineException;

class DoctrineElectionRepository implements ElectionRepositoryInterface
{
    private $cache = array();

    public function __construct($doctrine)
    {
        $this->em = $doctrine->getManager();
        $this->nuanceOptimizer = new DoctrineElectionRepositoryScoreByNuanceOptimizer($doctrine);
        $this->sameElectionOptimizer = new DoctrineElectionRepositoryScoreSameElectionOptimizer($doctrine);
        $this->cache['getVoteInfo'] = new \SplObjectStorage();
    }

    public function add(Election $element)
    {
        $this
            ->em
            ->persist($element);
    }

    /**
     * Retourne un objet Election à partir d'une échéance et d'un territoire.
     * Peut retourner l'objet élection d'un échelon territorial plus élevé.
     * Par exemple si on demande l'échéance européenne 2009 à Lille, on a
     * l'élection européenne de 2009 de la circo grand nord.
     *
     * @param Echeance           $echeance        L'échéance.
     * @param AbstractTerritoire $circonscription La circonscription.
     *
     * @return ELection Un objet élection.
     */
    public function get(
        Echeance $echeance,
        AbstractTerritoire $circonscription
    ) {
        $circonscriptions = array();

        $circonscriptions[] = $circonscription;

        /**
         * Ajouter dans le tableau $circonscriptions tous les territoires
         * parents.
         */
        if (end($circonscriptions) instanceof Commune) {
            $circonscriptions[] = end($circonscriptions)->getDepartement();
        }

        if (end($circonscriptions) instanceof Departement) {
            $circonscriptions[] = end($circonscriptions)
                ->getRegion()
            ;
        }

        if (end($circonscriptions) instanceof Region) {
            if (end($circonscriptions)->getCirconscriptionEuropeenne()) {
                $circonscriptions[] =
                    end($circonscriptions)->getCirconscriptionEuropeenne();
            }

            $circonscriptions[] = end($circonscriptions)->getPays();
        }

        if (end($circonscriptions) instanceof CirconscriptionEuropeenne) {
            $circonscriptions[] = end($circonscriptions)->getPays();
        }

        /*
         * Récupérer une élection pour cette échéance sur un des territoires du
         * tableau.
         */
        try {
            return $this
                ->em
                ->createQuery(
                    'SELECT election
                    FROM AppBundle\Domain\Election\Entity\Election\Election
                        election
                    WHERE
                        election.echeance = :echeance
                        AND election.circonscription IN (:circonscriptions)'
                )
                ->setMaxResults(1)
                ->setParameters(array(
                    'echeance' => $echeance,
                    'circonscriptions' => $circonscriptions,
                ))
                ->getOneOrNullResult()
            ;
        } catch (\Doctrine\ORM\ORMInvalidArgumentException $exception) {
            return;
        }
    }

    public function getScore(
        Echeance $echeance,
        $territoire,
        $candidat
    ) {
        /*
         * Si le territoire est un tableau, on boucle sur la fonction elle-meme
         * et on attribue le total à $$score.
         */
        if (
            is_array($territoire)
            || $territoire instanceof \ArrayAccess
            || $territoire instanceof \IteratorAggregate
        ) {
            $score = 0;
            foreach ($territoire as $division) {
                $scoreVO = $this->getScore($echeance, $division, $candidat);
                $score += $scoreVO->toVoix();
            }
            if (!$score) {
                return new Score();
            }
            $score = Score::fromVoix($score);
        }

        /**
         * Si $score est vide est qu'on a un groupe de nuance, on passe par
         * le NuanceOptimizer et retourne.
         */
        if (
            (!isset($score) || !$score)
            && $candidat instanceof CandidatNuanceSpecification
        ) {
            $score = $this->nuanceOptimizer->getScore(
                $echeance,
                $territoire,
                $candidat
            );

            return $score ?
                Score::fromVoixAndExprimes(
                    $score->toVoix(),
                    $this->getVoteInfo($echeance, $territoire)->getExprimes()
                )
                : new Score();
        }

        /*
         * Si on a pas retourné avec le NuanceOptimizer et que le score est vide
         * et qu'on a un candidat unique à une élection connue on passe par
         * l'election optimizer.
         */
        if (
            (!isset($score) || !$score)
            && $candidat instanceof Candidat && $this->get($echeance, $territoire)
        ) {
            $score = $this->sameElectionOptimizer->getScore(
                $echeance,
                $territoire,
                $candidat
            );

            return $score ?
                Score::fromVoixAndExprimes(
                    $score->toVoix(),
                    $this->getVoteInfo($echeance, $territoire)->getExprimes()
                )
                : new Score();
        }

        /*
         * En dernier recours on fait une requete classique sur l'unique
         * territoire.
         */
        if (!isset($score) || !$score) {
            $score = $this->doScoreQuery($echeance, $territoire, $candidat);
        }

        /*
         * Et si on a toujours rien, on fait une requete de consolidation
         * des échelons plus petits.
         */
        if (!$score) {
            if ($territoire instanceof Region) {
                $score = $this->doScoreRegionQuery(
                    $echeance,
                    $territoire,
                    $candidat
                );
            }
            if ($territoire instanceof Departement) {
                $score = $this->doScoreDepartementQuery(
                    $echeance,
                    $territoire,
                    $candidat
                );
            }
            if ($territoire instanceof CirconscriptionEuropeenne) {
                $score = $this->getScore(
                    $echeance,
                    $territoire->getRegions(),
                    $candidat
                );
            }
            if ($territoire instanceof Pays) {
                $score = $this->getScore(
                    $echeance,
                    $territoire->getCirconscriptionsEuropeennes(),
                    $candidat
                );
            }
        }

        return $score ?
            Score::fromVoixAndExprimes(
                $score->toVoix(),
                $this->getVoteInfo($echeance, $territoire)->getExprimes()
            )
            : new Score();
    }

    /**
     * Récupérer les VoteInfo (exprimés votants etc.) d'une échéance sur
     * un territoire. Avec des vrais morceaux de mise en cache inside.
     *
     * @param Echeance $echeance   [description]
     * @param [type]   $territoire [description]
     *
     * @return [type] [description]
     */
    public function getVoteInfo(Echeance $echeance, $territoire)
    {
        if (
            is_array($territoire)
            || $territoire instanceof \ArrayAccess
            || $territoire instanceof \IteratorAggregate
        ) {
            $exprimes = 0;
            $votants = 0;
            $inscrits = 0;
            foreach ($territoire as $division) {
                $voteInfoVO = $this->getVoteInfo($echeance, $division);
                if ($voteInfoVO) {
                    $exprimes += $voteInfoVO->getExprimes();
                    $votants += $voteInfoVO->getVotants();
                    $inscrits += $voteInfoVO->getInscrits();
                }
            }
            if (!$exprimes && !$votants && !$inscrits) {
                return new VoteInfo(null, null, null);
            }
            $voteInfo = new VoteInfo($inscrits, $votants, $exprimes);
        }

        if (
            isset($this->cache['getVoteInfo'][$echeance])
            && isset($this->cache['getVoteInfo'][$echeance][$territoire])
        ) {
            return $this->cache['getVoteInfo'][$echeance][$territoire];
        }

        if (!isset($voteInfo) || !$voteInfo || !$voteInfo->getExprimes()) {
            $voteInfo = $this->doVoteInfoQuery($echeance, $territoire);
        }

        if (!$voteInfo || !$voteInfo->getExprimes()) {
            if ($territoire instanceof Region) {
                $voteInfo = $this->doVoteInfoRegionQuery(
                    $echeance,
                    $territoire
                );
            }
            if ($territoire instanceof Departement) {
                $voteInfo = $this->doVoteInfoDepartementQuery(
                    $echeance,
                    $territoire
                );
            }
            if ($territoire instanceof CirconscriptionEuropeenne) {
                $voteInfo = $this->doVoteInfoCircoEuroQuery(
                    $echeance,
                    $territoire
                );
            }
            if ($territoire instanceof Pays) {
                $voteInfo = $this->getVoteInfo(
                    $echeance,
                    $territoire->getCirconscriptionsEuropeennes()
                );
            }
        }

        if (!isset($this->cache['getVoteInfo'][$echeance])) {
            $this->cache['getVoteInfo'][$echeance] = new \SplObjectStorage();
        }
        $this->cache['getVoteInfo'][$echeance][$territoire] = $voteInfo;

        return $voteInfo;
    }

    public function remove(Election $element)
    {
        $this->em->remove($element);
    }

    public function save()
    {
        try {
            $this->em->flush();
        } catch (DoctrineException $exception) {
            throw new UniqueConstraintViolationException(
                $exception->getMessage()
            );
        } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
            throw new UniqueConstraintViolationException(
                $exception->getMessage()
            );
        }

        $this->cache['getVoteInfo'] = new \SplObjectStorage();
        $this->nuanceOptimizer->reset();
        $this->sameElectionOptimizer->reset();
    }

    private function doScoreDepartementQuery(
        Echeance $echeance,
        Departement $territoire,
        $candidat
    ) {
        $query = $this
            ->em
            ->createQuery(
                'SELECT SUM(score.scoreVO.voix)
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Commune
                    territoire,
                    AppBundle\Domain\Election\Entity\Election\ScoreAssignment
                    score
                JOIN score.election election
                WHERE territoire.departement  = :territoire
                    AND score.territoire = territoire
                    AND score.candidat
                        IN ('.$this->getCandidatSubquery($candidat).')
                    AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;
        if ($candidat instanceof CandidatNuanceSpecification) {
            $query->setParameter('nuances', $candidat->getNuances());
        } else {
            $query->setParameter('candidat', $candidat);
        }

        $result = $query->getSingleScalarResult();

        return $result ? Score::fromVoix($result) : null;
    }

    private function doScoreRegionQuery(
        Echeance $echeance,
        Region $territoire,
        $candidat
    ) {
        $query = $this
            ->em
            ->createQuery(
                'SELECT SUM(score.scoreVO.voix) as total, territoire.id
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Departement
                    departement,
                    AppBundle\Domain\Election\Entity\Election\ScoreAssignment
                    score
                JOIN score.election election
                JOIN score.territoire territoire
                WHERE departement.region  = :territoire
                AND score.territoire = departement
                AND score.candidat
                    IN ('.$this->getCandidatSubquery($candidat).')
                AND election.echeance = :echeance'
            )
                        ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;
        if ($candidat instanceof CandidatNuanceSpecification) {
            $query->setParameter('nuances', $candidat->getNuances());
        } else {
            $query->setParameter('candidat', $candidat);
        }

        $departementsAcResultats = $query->getResult();
        $result = $departementsAcResultats[0]['total'];
        $departementsAcResultats = array_map(function ($line) {
            return $line['id'];
        }, $departementsAcResultats);
        $departementsAcResultats = array_filter(
            $departementsAcResultats,
            function ($element) {
                return ($element);
            }
        );

        $query = $this
            ->em
            ->createQuery(
                'SELECT SUM(score.scoreVO.voix)
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Departement
                    departement,
                    AppBundle\Domain\Territoire\Entity\Territoire\Commune
                    commune,
                    AppBundle\Domain\Election\Entity\Election\ScoreAssignment
                    score
                JOIN score.election election
                JOIN score.territoire territoire
                WHERE departement.region  = :territoire
                    '.(
                        empty($departementsAcResultats) ? ''
                        : 'AND departement NOT IN (:departementsAcResultats)'
                    ).'
                    AND (
                        commune.departement = departement
                        AND score.territoire = commune
                    )
                    AND score.candidat
                        IN ('.$this->getCandidatSubquery($candidat).')
                    AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;
        if (!empty($departementsAcResultats)) {
            $query->setParameter('departementsAcResultats', $departementsAcResultats);
        }
        if ($candidat instanceof CandidatNuanceSpecification) {
            $query->setParameter('nuances', $candidat->getNuances());
        } else {
            $query->setParameter('candidat', $candidat);
        }

        $result += $query->getSingleScalarResult();

        return $result ? Score::fromVoix($result) : null;
    }

    private function doScoreQuery(
        Echeance $echeance,
        $territoire,
        $candidat
    ) {
        $query = $this
            ->em
            ->createQuery(
                'SELECT SUM(score.scoreVO.voix)
                FROM
                    AppBundle\Domain\Election\Entity\Election\ScoreAssignment
                    score
                JOIN score.election election
                WHERE  score.territoire  = :territoire
                    AND score.candidat
                        IN ('.$this->getCandidatSubquery($candidat).')
                    AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;
        if ($candidat instanceof CandidatNuanceSpecification) {
            $query->setParameter('nuances', $candidat->getNuances());
        } else {
            $query->setParameter('candidat', $candidat);
        }

        $result = $query->getSingleScalarResult();

        return $result ? Score::fromVoix($result) : null;
    }

    private function doVoteInfoCircoEuroQuery(
        Echeance $echeance,
        CirconscriptionEuropeenne $territoire
    ) {
        $query = $this
            ->em
            ->createQuery(
                'SELECT
                    territoire.id
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Region
                    region,
                    AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                    voteInfo
                JOIN voteInfo.election election
                JOIN voteInfo.territoire territoire
                WHERE region.circonscriptionEuropeenne  = :territoire
                AND voteInfo.territoire = region
                AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;

        $regionsAcResultats = $query->getResult();

        $query = $this
            ->em
            ->createQuery(
                'SELECT
                    territoire.id
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Region
                    region,
                    AppBundle\Domain\Territoire\Entity\Territoire\Departement
                    departement,
                    AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                    voteInfo
                JOIN voteInfo.election election
                JOIN voteInfo.territoire territoire
                WHERE region.circonscriptionEuropeenne = :territoire
                '.(
                        empty($regionsAcResultats) ? ''
                        : 'AND region.id NOT IN (:regionsAcResultats)'
                    ).'
                AND departement.region  = region
                AND voteInfo.territoire = departement
                AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;

        if (!empty($regionsAcResultats)) {
            $query->setParameter('regionsAcResultats', $regionsAcResultats);
        }

        $departementsAcResultats = $query->getResult();

        if (!empty($regionsAcResultats)) {
            $query = $this
                ->em
                ->createQuery(
                    'SELECT
                        SUM(voteInfo.voteInfoVO.exprimes) AS exprimes,
                        SUM(voteInfo.voteInfoVO.votants) AS votants,
                        SUM(voteInfo.voteInfoVO.inscrits) AS inscrits
                    FROM
                        AppBundle\Domain\Territoire\Entity\Territoire\Region
                        region,
                        AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                        voteInfo
                    JOIN voteInfo.election election
                    JOIN voteInfo.territoire territoire
                    WHERE region.circonscriptionEuropeenne  = :territoire
                    AND voteInfo.territoire = region
                    AND election.echeance = :echeance'
                )
                ->setParameters(array(
                    'echeance' => $echeance,
                    'territoire' => $territoire,
                ))
            ;

            $result0 = $query->getSingleResult();
        } else {
            $result0 = array('exprimes' => 0, 'votants' => 0, 'inscrits' => 0);
        }

        if (!empty($departementsAcResultats)) {
            $query = $this
                ->em
                ->createQuery(
                    'SELECT
                        SUM(voteInfo.voteInfoVO.exprimes) AS exprimes,
                        SUM(voteInfo.voteInfoVO.votants) AS votants,
                        SUM(voteInfo.voteInfoVO.inscrits) AS inscrits
                    FROM
                        AppBundle\Domain\Territoire\Entity\Territoire\Region
                        region,
                        AppBundle\Domain\Territoire\Entity\Territoire\Departement
                        departement,
                        AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                        voteInfo
                    JOIN voteInfo.election election
                    JOIN voteInfo.territoire territoire
                    WHERE region.circonscriptionEuropeenne = :territoire
                    '.(
                            empty($regionsAcResultats) ? ''
                            : 'AND region.id NOT IN (:regionsAcResultats)'
                        ).'
                    AND departement.region  = region
                    AND voteInfo.territoire = departement
                    AND election.echeance = :echeance'
                )
                ->setParameters(array(
                    'echeance' => $echeance,
                    'territoire' => $territoire,
                ))
            ;

            if (!empty($regionsAcResultats)) {
                $query->setParameter('regionsAcResultats', $regionsAcResultats);
            }

            $result1 = $query->getSingleResult();
        } else {
            $result1 = array('exprimes' => 0, 'votants' => 0, 'inscrits' => 0);
        }

        $query = $this
            ->em
            ->createQuery(
                'SELECT
                    SUM(voteInfo.voteInfoVO.exprimes) AS exprimes,
                    SUM(voteInfo.voteInfoVO.votants) AS votants,
                    SUM(voteInfo.voteInfoVO.inscrits) AS inscrits
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Region
                    region,
                    AppBundle\Domain\Territoire\Entity\Territoire\Departement
                    departement,
                    AppBundle\Domain\Territoire\Entity\Territoire\Commune
                    commune,
                    AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                    voteInfo
                JOIN voteInfo.election election
                JOIN voteInfo.territoire territoire
                WHERE region.circonscriptionEuropeenne= :territoire
                    '.(
                        empty($regionsAcResultats) ? ''
                        : 'AND region.id NOT IN (:regionsAcResultats)'
                    ).'
                    AND departement.region  = region
                    '.(
                        empty($departementsAcResultats) ? ''
                        : 'AND departement.id NOT IN (:departementsAcResultats)'
                    ).'
                    AND (
                        commune.departement = departement
                        AND voteInfo.territoire = commune
                    )
                    AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;
        if (!empty($departementsAcResultats)) {
            $query->setParameter('departementsAcResultats', $departementsAcResultats);
        }

        if (!empty($regionsAcResultats)) {
            $query->setParameter('regionsAcResultats', $regionsAcResultats);
        }

        $result2 = $query->getSingleResult();

        return !empty($result0) || !empty($result1) || !empty($result2) ? new VoteInfo(
            $result0['inscrits'] + $result1['inscrits'] + $result2['inscrits'],
            $result0['votants'] + $result1['votants'] + $result2['votants'],
            $result0['exprimes'] + $result1['exprimes'] + $result2['exprimes']
        ) : new VoteInfo(null, null, null);
    }

    private function doVoteInfoDepartementQuery(
        Echeance $echeance,
        Departement $territoire
    ) {
        $query = $this
            ->em
            ->createQuery(
                'SELECT
                    SUM(voteInfo.voteInfoVO.exprimes) AS exprimes,
                    SUM(voteInfo.voteInfoVO.votants) AS votants,
                    SUM(voteInfo.voteInfoVO.inscrits) AS inscrits
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Commune
                    territoire,
                    AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                    voteInfo
                JOIN voteInfo.election election
                WHERE territoire.departement  = :territoire
                    AND voteInfo.territoire = territoire
                    AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;

        $result = $query->getSingleResult();

        return !empty($result) ? new VoteInfo(
            $result['inscrits'],
            $result['votants'],
            $result['exprimes']
        ) : new VoteInfo(null, null, null);
    }

    private function doVoteInfoRegionQuery(
        Echeance $echeance,
        Region $territoire
    ) {
        $query = $this
            ->em
            ->createQuery(
                'SELECT
                    territoire.id
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Departement
                    departement,
                    AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                    voteInfo
                JOIN voteInfo.election election
                JOIN voteInfo.territoire territoire
                WHERE departement.region  = :territoire
                AND voteInfo.territoire = departement
                AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;

        $departementsAcResultats = $query->getResult();

        if (!empty($departementsAcResultats)) {
            $query = $this
                ->em
                ->createQuery(
                    'SELECT
                        SUM(voteInfo.voteInfoVO.exprimes) AS exprimes,
                        SUM(voteInfo.voteInfoVO.votants) AS votants,
                        SUM(voteInfo.voteInfoVO.inscrits) AS inscrits
                    FROM
                        AppBundle\Domain\Territoire\Entity\Territoire\Departement
                        departement,
                        AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                        voteInfo
                    JOIN voteInfo.election election
                    JOIN voteInfo.territoire territoire
                    WHERE departement.region  = :territoire
                    AND voteInfo.territoire = departement
                    AND election.echeance = :echeance'
                )
                ->setParameters(array(
                    'echeance' => $echeance,
                    'territoire' => $territoire,
                ))
            ;
            $result = $query->getSingleResult();
        } else {
            $result = array('exprimes' => 0, 'votants' => 0, 'inscrits' => 0);
        }

        $query = $this
            ->em
            ->createQuery(
                'SELECT
                    SUM(voteInfo.voteInfoVO.exprimes) AS exprimes,
                    SUM(voteInfo.voteInfoVO.votants) AS votants,
                    SUM(voteInfo.voteInfoVO.inscrits) AS inscrits
                FROM
                    AppBundle\Domain\Territoire\Entity\Territoire\Departement
                    departement,
                    AppBundle\Domain\Territoire\Entity\Territoire\Commune
                    commune,
                    AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                    voteInfo
                JOIN voteInfo.election election
                JOIN voteInfo.territoire territoire
                WHERE departement.region  = :territoire
                    '.(
                        empty($departementsAcResultats) ? ''
                        : 'AND departement NOT IN (:departementsAcResultats)'
                    ).'
                    AND (
                        commune.departement = departement
                        AND voteInfo.territoire = commune
                    )
                    AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;
        if (!empty($departementsAcResultats)) {
            $query->setParameter('departementsAcResultats', $departementsAcResultats);
        }

        $result2 = $query->getSingleResult();

        return !empty($result) || !empty($result2) ? new VoteInfo(
            $result['inscrits'] + $result2['inscrits'],
            $result['votants'] + $result2['votants'],
            $result['exprimes'] + $result2['exprimes']
        ) : new VoteInfo(null, null, null);
    }

    private function doVoteInfoQuery(
        Echeance $echeance,
        $territoire
    ) {
        $query = $this
            ->em
            ->createQuery(
                'SELECT
                    SUM(voteInfo.voteInfoVO.exprimes) AS exprimes,
                    SUM(voteInfo.voteInfoVO.votants) AS votants,
                    SUM(voteInfo.voteInfoVO.inscrits) AS inscrits
                FROM
                    AppBundle\Domain\Election\Entity\Election\VoteInfoAssignment
                    voteInfo
                JOIN voteInfo.election election
                WHERE  voteInfo.territoire  = :territoire
                    AND election.echeance = :echeance'
            )
            ->setParameters(array(
                'echeance' => $echeance,
                'territoire' => $territoire,
            ))
        ;

        $result = $query->getSingleResult();

        return !empty($result) ? new VoteInfo(
            $result['inscrits'],
            $result['votants'],
            $result['exprimes']
        ) : new VoteInfo(null, null, null);
    }

    private function getCandidatSubquery($candidat, $n = 0)
    {
        if ($candidat instanceof CandidatNuanceSpecification) {
            return $this
                ->em
                ->createQuery(
                    'SELECT candidat'.$n.'
                    FROM
                        AppBundle\Domain\Election\Entity\Candidat\Candidat
                        candidat'.$n.'
                    WHERE candidat'.$n.'.nuance IN (:nuances)'
                )
                ->getDQL()
            ;
        }

        return $this
            ->em
            ->createQuery(
                'SELECT candidat'.$n.'
                FROM
                    AppBundle\Domain\Election\Entity\Candidat\Candidat
                    candidat'.$n.'
                WHERE candidat'.$n.' IN (:candidat)'
            )
            ->getDQL()
        ;
    }
}
