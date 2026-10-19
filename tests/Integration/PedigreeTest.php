<?php

declare(strict_types=1);

/** Family tree: ancestors, descendants and inbreeding coefficient. */
final class PedigreeTest extends DatabaseTestCase
{
    private string $cage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cage = TestDatabase::cage($this->con, false);
    }

    private function born(?int $sire, ?int $dam, ?int $litter = null): int
    {
        $id = TestDatabase::animal($this->con, $this->cage);
        db_execute($this->con, 'INSERT INTO animal_parentage (animal_id, litter_id, sire_id, dam_id) VALUES (?, ?, ?, ?)', 'iiii', [$id, $litter, $sire, $dam]);
        return $id;
    }

    private function inbreeding(int $id): float
    {
        return pedigree_inbreeding(pedigree_parent_map($this->con, [$id], PEDIGREE_MAX_GENERATIONS), $id);
    }

    public function testInbreedingOfClassicMatings(): void
    {
        [$sire, $dam, $other] = [TestDatabase::animal($this->con, $this->cage), TestDatabase::animal($this->con, $this->cage), TestDatabase::animal($this->con, $this->cage)];
        $brother = $this->born($sire, $dam);
        $sister = $this->born($sire, $dam);
        $halfSister = $this->born($sire, $other);

        $this->assertSame(0.0, $this->inbreeding($brother), 'unrelated parents');
        $this->assertEqualsWithDelta(0.25, $this->inbreeding($this->born($brother, $sister)), 1e-9, 'full sibs');
        $this->assertEqualsWithDelta(0.125, $this->inbreeding($this->born($brother, $halfSister)), 1e-9, 'half sibs');
        $this->assertEqualsWithDelta(0.25, $this->inbreeding($this->born($sire, $sister)), 1e-9, 'father x daughter');
        // Two generations of full-sib mating: 0.375.
        $f1a = $this->born($brother, $sister);
        $f1b = $this->born($brother, $sister);
        $this->assertEqualsWithDelta(0.375, $this->inbreeding($this->born($f1a, $f1b)), 1e-9, 'second full-sib generation');
    }

    public function testTreeRepeatedAncestorsAndDescendants(): void
    {
        [$sire, $dam] = [TestDatabase::animal($this->con, $this->cage), TestDatabase::animal($this->con, $this->cage)];
        $this->con->query("INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, sire_id, dam_id) VALUES ('{$this->cage}', '2026-01-01', 2, 0, $sire, $dam)");
        $litter = (int) $this->con->insert_id;
        $brother = $this->born($sire, $dam, $litter);
        $sister = $this->born($sire, $dam, $litter);
        $pup = $this->born($brother, $sister);

        $parents = pedigree_parent_map($this->con, [$pup], 3);
        $tree = pedigree_ancestor_tree($parents, $pup, 3);
        $this->assertSame($brother, $tree['sire']['id']);
        $this->assertSame($dam, $tree['dam']['dam']['id']);
        $this->assertNull($tree['sire']['sire']['sire']['id'], 'beyond the known pedigree');
        $this->assertSame([$sire => 2, $dam => 2], pedigree_repeated_ancestors($tree));

        $desc = pedigree_descendants($this->con, $sire, 2);
        $this->assertCount(1, $desc);
        $this->assertSame($litter, $desc[0]['litter']['id']);
        $this->assertSame($dam, $desc[0]['partner']);
        $this->assertSame([$brother, $sister], array_column($desc[0]['children'], 'id'));
        $this->assertSame($pup, $desc[0]['children'][0]['descendants'][0]['children'][0]['id'], 'grandchild');
    }
}
