<?php

use yii\db\Migration;

class m260002_000003_fix_registration_number_counter extends Migration
{
    public function safeUp()
    {
        $this->execute("CREATE TABLE IF NOT EXISTS registration_counters (
            queue_date DATE PRIMARY KEY,
            last_number INTEGER NOT NULL DEFAULT 0 CHECK(last_number >= 0),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )");

        $this->execute(<<<'SQL'
DO $$
DECLARE
    rec RECORD;
    next_num INTEGER;
BEGIN
    FOR rec IN
        SELECT r.id, r.registration_date
        FROM registrations r
        JOIN (
            SELECT registration_number
            FROM registrations
            GROUP BY registration_number
            HAVING COUNT(*) > 1
        ) d ON d.registration_number = r.registration_number
        WHERE r.id <> (
            SELECT MIN(x.id) FROM registrations x WHERE x.registration_number = r.registration_number
        )
        ORDER BY r.registration_date, r.id
    LOOP
        SELECT COALESCE(MAX(SUBSTRING(registration_number FROM '^RJ[0-9]{8}([0-9]+)$')::INTEGER), 0) + 1
        INTO next_num
        FROM registrations
        WHERE registration_date = rec.registration_date;

        UPDATE registrations
        SET registration_number = 'RJ' || TO_CHAR(rec.registration_date, 'YYYYMMDD') || LPAD(next_num::TEXT, 4, '0')
        WHERE id = rec.id;
    END LOOP;
END $$;
SQL);

        $this->execute(<<<'SQL'
INSERT INTO registration_counters (queue_date, last_number)
SELECT registration_date,
       COALESCE(MAX(SUBSTRING(registration_number FROM '^RJ[0-9]{8}([0-9]+)$')::INTEGER), 0)
FROM registrations
GROUP BY registration_date
ON CONFLICT (queue_date) DO UPDATE
SET last_number = GREATEST(registration_counters.last_number, EXCLUDED.last_number),
    updated_at = NOW()
SQL);
    }

    public function safeDown()
    {
        $this->execute("DROP TABLE IF EXISTS registration_counters");
    }
}
