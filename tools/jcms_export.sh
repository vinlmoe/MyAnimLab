#!/usr/bin/env bash
# Read-only export of the JCMS objects used by MyVivarium. Genotyping tables
# are deliberately excluded. Requires mdbtools (mdb-export).
set -euo pipefail

SOURCE=${1:?Usage: tools/jcms_export.sh /path/to/JCMS.mdb [output-directory]}
OUTPUT=${2:-jcms-export}
mkdir -p "$OUTPUT"

for table in Strain1 Room1 Container2 ContainerHistory2 cv_ContainerStatus2 Mouse1 \
  Mating1 MatingUnitLink1 Litter1 ExpPlan1 ExpTest1 ExpPlanMouseMap1 \
  ExpTestPlanMap1 ExpData1 MouseUsage1 HealthLevelHistory3 cv_HealthLevel3 Level3; do
  mdb-export -D '%Y-%m-%d' -T '%Y-%m-%d %H:%M:%S' "$SOURCE" "$table" > "$OUTPUT/$table.csv"
done

printf 'Export complete: %s\n' "$OUTPUT"
