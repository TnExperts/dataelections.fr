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

namespace AppBundle\Domain\Territoire\Entity\Territoire;

class ArrondissementCommunal extends AbstractTerritoire
{
    /**
     * Le code de l'arrondissement. On suit la convention 056AR01 pour
     * arrondissement 1 de Paris, 055SR01 pour les secteurs de Marseille.
     *
     * @var int
     */
    private $code;

    /**
     * La commune de l'arrondissement.
     *
     * @var Commune
     */
    private $commune;

    /**
     * Constructeur d'objet ArronissementCommunal.
     *
     * @param Commune $commune La commune de l'arrondissement.
     * @param int     $code    Le code de l'arrondissement. On suit la
     *                         convention 056AR01 pour le 1er
     *                         arrondissement de Paris, 05SR07 pour le
     *                         secteur 7 de Marseille.
     * @param string  $nom     Le nom de l'arrondissement.
     */
    public function __construct(Commune $commune, $code, $nom)
    {
        \Assert\that((string) $code)->maxLength(10);
        \Assert\that($nom)
            ->string()
            ->maxLength(
                255,
                'Le nom de l\'arrondissement ne peut dépasser 255 caractères.'
            )
        ;

        $this->commune = $commune;
        $commune->addArrondissement($this);
        $this->code = $code;
        $this->nom = $nom;
    }

    /**
     * Récupérer le code INSEE de l'arrondissement.
     *
     * @return int Le code INSEE de l'arrondissement.
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Récupérer le département de l'arrondissement.
     *
     * @return Departement Le département de l'arrondissement.
     */
    public function getCommune()
    {
        return $this->commune;
    }
}
