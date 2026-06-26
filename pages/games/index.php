<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$db = getDb();

// Sport filter for association admins
$sportFilterId = hasRole('association_admin') ? (int)$_SESSION['user_association_id'] : null;

// Get all sports (filtered for association admins)
$sports = $sportFilterId
    ? [$db->fetchOne("SELECT * FROM sports_disciplines WHERE id = ?", [$sportFilterId])]
    : getSports();

// Get standings helper
function getStandings($db, $sportId = null, $groupLabel = null) {
    $where = ["m.status = 'completed'"];
    $params = [];
    if ($sportId) { $where[] = "m.sport_discipline_id = ?"; $params[] = $sportId; }
    if ($groupLabel) { $where[] = "m.group_label = ?"; $params[] = $groupLabel; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $rows = $db->fetchAll(
        "SELECT m.home_county_id, m.away_county_id, m.home_score, m.away_score, m.group_label, m.sport_discipline_id,
                c1.name as home_name, c2.name as away_name
         FROM matches m
         JOIN counties c1 ON m.home_county_id = c1.id
         JOIN counties c2 ON m.away_county_id = c2.id
         $whereSql
         ORDER BY m.group_label, m.match_date",
        $params
    );

    $teams = [];
    $teamInfo = [];

    foreach ($rows as $r) {
        foreach ([
            ['id' => $r['home_county_id'], 'name' => $r['home_name'], 'score' => $r['home_score'], 'opp_score' => $r['away_score']],
            ['id' => $r['away_county_id'], 'name' => $r['away_name'], 'score' => $r['away_score'], 'opp_score' => $r['home_score']]
        ] as $t) {
            $tid = $t['id'];
            $teamInfo[$tid] = ['name' => $t['name'], 'group' => $r['group_label']];
            if (!isset($teams[$tid])) {
                $teams[$tid] = ['played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0, 'pts' => 0];
            }
            $teams[$tid]['played']++;
            $teams[$tid]['gf'] += (int)$t['score'];
            $teams[$tid]['ga'] += (int)$t['opp_score'];
            $teams[$tid]['gd'] = $teams[$tid]['gf'] - $teams[$tid]['ga'];
            if ((int)$t['score'] > (int)$t['opp_score']) { $teams[$tid]['wins']++; $teams[$tid]['pts'] += 3; }
            elseif ((int)$t['score'] === (int)$t['opp_score']) { $teams[$tid]['draws']++; $teams[$tid]['pts'] += 1; }
            else { $teams[$tid]['losses']++; }
        }
    }

    // Sort: points desc, GD desc, GF desc
    uksort($teams, function($a, $b) use ($teams) {
        if ($teams[$a]['pts'] !== $teams[$b]['pts']) return $teams[$b]['pts'] - $teams[$a]['pts'];
        if ($teams[$a]['gd'] !== $teams[$b]['gd']) return $teams[$b]['gd'] - $teams[$a]['gd'];
        return $teams[$b]['gf'] - $teams[$a]['gf'];
    });

    return [$teams, $teamInfo];
}

// Get live/upcoming matches
$liveFilter = $sportFilterId ? " AND m.sport_discipline_id = $sportFilterId" : "";
$liveMatches = $db->fetchAll("
    SELECT m.*, s.name as sport_name, c1.name as home_name, c2.name as away_name
    FROM matches m
    JOIN sports_disciplines s ON m.sport_discipline_id = s.id
    JOIN counties c1 ON m.home_county_id = c1.id
    JOIN counties c2 ON m.away_county_id = c2.id
    WHERE m.status IN ('live','scheduled') $liveFilter
    ORDER BY m.match_date ASC
    LIMIT 50
");

// Get recent completed matches
$completedMatches = $db->fetchAll("
    SELECT m.*, s.name as sport_name, c1.name as home_name, c2.name as away_name
    FROM matches m
    JOIN sports_disciplines s ON m.sport_discipline_id = s.id
    JOIN counties c1 ON m.home_county_id = c1.id
    JOIN counties c2 ON m.away_county_id = c2.id
    WHERE m.status = 'completed' $liveFilter
    ORDER BY m.updated_at DESC
    LIMIT 20
");

// Get standings per group
$standingsByGroup = [];
foreach (['A', 'B', 'C', 'D'] as $g) {
    list($teams, $teamInfo) = getStandings($db, $sportFilterId, $g);
    if (!empty($teams)) {
        $standingsByGroup[$g] = ['teams' => $teams, 'info' => $teamInfo];
    }
}

$pageTitle = 'Games & Live Scores';
?>
<?php include __DIR__ . '/../../templates/header.php'; ?>

<!-- Auto-refresh every 30 seconds -->
<script>
setTimeout(function(){ location.reload(); }, 30000);
</script>

<style>
.score-display { font-size: 2.5rem; font-weight: 700; line-height: 1; }
.score-separator { font-size: 2rem; font-weight: 300; padding: 0 0.75rem; }
.live-badge { animation: pulse 1.5s infinite; }
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}
.team-name-cell { max-width: 180px; }
.standing-table th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
.standing-table td { vertical-align: middle; }
.pos-1 { background: rgba(40,167,69,0.08); }
.pos-2 { background: rgba(0,123,255,0.05); }
.match-card { transition: box-shadow 0.2s; }
.match-card:hover { box-shadow: 0 0.125rem 0.5rem rgba(0,0,0,0.08); }
</style>

<div class="row mb-3">
    <div class="col">
        <h4 class="mb-0"><i class="bi bi-broadcast me-2"></i>Live Scores</h4>
    </div>
    <div class="col text-end">
        <a href="<?= APP_URL ?>pages/games/standings.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-trophy me-1"></i>Standings</a>
        <?php if (hasRole(['super_admin'])): ?>
        <a href="<?= APP_URL ?>pages/games/manage.php" class="btn btn-primary btn-sm"><i class="bi bi-gear me-1"></i>Manage Games</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($liveMatches)): ?>
<div class="row g-3 mb-4">
    <?php foreach ($liveMatches as $m): ?>
    <div class="col-lg-4 col-md-6">
        <div class="card match-card border-<?= $m['status'] === 'live' ? 'danger' : 'secondary' ?>">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <small class="text-muted"><?= sanitize($m['sport_name']) ?> · Group <?= $m['group_label'] ?></small>
                <?php if ($m['status'] === 'live'): ?>
                <span class="badge bg-danger live-badge"><i class="bi bi-broadcast me-1"></i>LIVE</span>
                <?php else: ?>
                <small class="text-muted"><?= formatDate($m['match_date'], 'M d, h:i A') ?></small>
                <?php endif; ?>
            </div>
            <div class="card-body text-center py-3">
                <div class="row align-items-center">
                    <div class="col-5 text-truncate">
                        <?php $flagUrl = getCountyFlagUrl($m['home_name']); ?>
                        <?php if ($flagUrl): ?><img src="<?= $flagUrl ?>" alt="" style="width:18px;height:18px;object-fit:contain;border-radius:50%;margin-right:4px;vertical-align:middle;"><?php endif; ?>
                        <strong><?= sanitize($m['home_name']) ?></strong>
                    </div>
                    <div class="col-2">
                        <?php if ($m['home_score'] !== null): ?>
                        <span class="score-display"><?= (int)$m['home_score'] ?></span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-2">
                        <?php if ($m['away_score'] !== null): ?>
                        <span class="score-display"><?= (int)$m['away_score'] ?></span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-3 text-truncate">
                        <strong><?= sanitize($m['away_name']) ?></strong>
                        <?php $flagUrl = getCountyFlagUrl($m['away_name']); ?>
                        <?php if ($flagUrl): ?><img src="<?= $flagUrl ?>" alt="" style="width:18px;height:18px;object-fit:contain;border-radius:50%;margin-left:4px;vertical-align:middle;"><?php endif; ?>
                    </div>
                </div>
                <?php if ($m['status'] === 'scheduled'): ?>
                <small class="text-muted mt-2 d-block"><?= sanitize($m['round']) ?></small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($completedMatches)): ?>
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Results</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Sport</th>
                        <th>Group</th>
                        <th>Home</th>
                        <th class="text-center">Score</th>
                        <th>Away</th>
                        <th>Round</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completedMatches as $m): ?>
                    <tr>
                        <td><small><?= sanitize($m['sport_name']) ?></small></td>
                        <td><span class="group-badge group-<?= $m['group_label'] ?>"><?= $m['group_label'] ?></span></td>
                        <td class="team-name-cell">
                            <?php $flagUrl = getCountyFlagUrl($m['home_name']); ?>
                            <?php if ($flagUrl): ?><img src="<?= $flagUrl ?>" alt="" style="width:16px;height:16px;object-fit:contain;border-radius:50%;margin-right:4px;vertical-align:middle;"><?php endif; ?>
                            <?= sanitize($m['home_name']) ?>
                        </td>
                        <td class="text-center">
                            <strong class="fs-5"><?= (int)$m['home_score'] ?></strong>
                            <span class="mx-1 text-muted">-</span>
                            <strong class="fs-5"><?= (int)$m['away_score'] ?></strong>
                        </td>
                        <td class="team-name-cell">
                            <?= sanitize($m['away_name']) ?>
                            <?php $flagUrl = getCountyFlagUrl($m['away_name']); ?>
                            <?php if ($flagUrl): ?><img src="<?= $flagUrl ?>" alt="" style="width:16px;height:16px;object-fit:contain;border-radius:50%;margin-left:4px;vertical-align:middle;"><?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= sanitize($m['round']) ?></small></td>
                        <td><small class="text-muted"><?= formatDate($m['match_date']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($standingsByGroup)): ?>
<div class="row g-3">
    <?php foreach (['A', 'B', 'C', 'D'] as $g): ?>
    <?php if (isset($standingsByGroup[$g])): ?>
    <div class="col-lg-3 col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white py-2">
                <h6 class="mb-0">Group <?= $g ?> Standings</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm standing-table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Team</th>
                            <th class="text-center">P</th>
                            <th class="text-center">W</th>
                            <th class="text-center">D</th>
                            <th class="text-center">L</th>
                            <th class="text-center">GF</th>
                            <th class="text-center">GA</th>
                            <th class="text-center">GD</th>
                            <th class="text-center">Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 0;
                        foreach ($standingsByGroup[$g]['teams'] as $tid => $s): 
                            $rank++;
                        ?>
                        <tr class="<?= $rank <= 2 ? ($rank === 1 ? 'pos-1' : 'pos-2') : '' ?>">
                            <td class="text-center"><?= $rank ?></td>
                            <td class="team-name-cell"><small><?= sanitize($standingsByGroup[$g]['info'][$tid]['name']) ?></small></td>
                            <td class="text-center"><?= $s['played'] ?></td>
                            <td class="text-center"><?= $s['wins'] ?></td>
                            <td class="text-center"><?= $s['draws'] ?></td>
                            <td class="text-center"><?= $s['losses'] ?></td>
                            <td class="text-center"><?= $s['gf'] ?></td>
                            <td class="text-center"><?= $s['ga'] ?></td>
                            <td class="text-center"><?= $s['gd'] > 0 ? '+' : '' ?><?= $s['gd'] ?></td>
                            <td class="text-center"><strong><?= $s['pts'] ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-lg-3 col-md-6">
        <div class="card h-100">
            <div class="card-header bg-white py-2">
                <h6 class="mb-0">Group <?= $g ?> Standings</h6>
            </div>
            <div class="card-body text-center text-muted py-4">
                <small>No matches played yet</small>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
