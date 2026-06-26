<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['super_admin']);

$db = getDb();

// Handle form actions
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $sport_discipline_id = (int)($_POST['sport_discipline_id']);
        $home_county_id = (int)($_POST['home_county_id']);
        $away_county_id = (int)($_POST['away_county_id']);
        $match_date = $_POST['match_date'] ?? '';
        $group_label = $_POST['group_label'] ?? '';
        $round = sanitize($_POST['round'] ?? 'Group Stage');
        $status = $_POST['status'] ?? 'scheduled';
        $home_score = $_POST['home_score'] !== '' ? (int)$_POST['home_score'] : null;
        $away_score = $_POST['away_score'] !== '' ? (int)$_POST['away_score'] : null;
        $notes = sanitize($_POST['notes'] ?? '');

        if ($home_county_id === $away_county_id) {
            $msg = '<div class="alert alert-danger">Home and Away teams cannot be the same.</div>';
        } elseif (empty($match_date)) {
            $msg = '<div class="alert alert-danger">Match date is required.</div>';
        } else {
            if ($action === 'create') {
                $db->insert(
                    "INSERT INTO matches (sport_discipline_id, home_county_id, away_county_id, home_score, away_score, match_date, status, group_label, round, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$sport_discipline_id, $home_county_id, $away_county_id, $home_score, $away_score, $match_date, $status, $group_label, $round, $notes, $_SESSION['user_id']]
                );
                logActivity('create_match', "Created match: $home_county_id vs $away_county_id");
                $msg = '<div class="alert alert-success">Match created successfully.</div>';
            } else {
                $db->update(
                    "UPDATE matches SET sport_discipline_id=?, home_county_id=?, away_county_id=?, home_score=?, away_score=?, match_date=?, status=?, group_label=?, round=?, notes=?, updated_at=NOW() WHERE id=?",
                    [$sport_discipline_id, $home_county_id, $away_county_id, $home_score, $away_score, $match_date, $status, $group_label, $round, $notes, $id]
                );
                logActivity('update_match', "Updated match #$id");
                $msg = '<div class="alert alert-success">Match updated successfully.</div>';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->delete("DELETE FROM matches WHERE id = ?", [$id]);
        logActivity('delete_match', "Deleted match #$id");
        $msg = '<div class="alert alert-success">Match deleted.</div>';
    }

    if ($action === 'update_score') {
        $id = (int)($_POST['id'] ?? 0);
        $home_score = $_POST['home_score'] !== '' ? (int)$_POST['home_score'] : null;
        $away_score = $_POST['away_score'] !== '' ? (int)$_POST['away_score'] : null;
        $status = $_POST['status'] ?? 'completed';
        $db->update(
            "UPDATE matches SET home_score=?, away_score=?, status=?, updated_at=NOW() WHERE id=?",
            [$home_score, $away_score, $status, $id]
        );
        logActivity('update_score', "Updated score match #$id: $home_score - $away_score");
        $msg = '<div class="alert alert-success">Score updated.</div>';
    }
}

// Get all matches
$matches = $db->fetchAll("
    SELECT m.*, s.name as sport_name, c1.name as home_name, c2.name as away_name
    FROM matches m
    JOIN sports_disciplines s ON m.sport_discipline_id = s.id
    JOIN counties c1 ON m.home_county_id = c1.id
    JOIN counties c2 ON m.away_county_id = c2.id
    ORDER BY m.match_date DESC
    LIMIT 100
");

// Kickball standings helper
function getManageKickballStandings($db, $groupLabel = null) {
    $where = ["m.status = 'completed'", "m.sport_discipline_id = 2"];
    $params = [];
    if ($groupLabel) { $where[] = "m.group_label = ?"; $params[] = $groupLabel; }
    $whereSql = "WHERE " . implode(" AND ", $where);

    $rows = $db->fetchAll(
        "SELECT m.home_county_id, m.away_county_id, m.home_score, m.away_score, m.group_label,
                c1.name as home_name, c2.name as away_name
         FROM matches m
         JOIN counties c1 ON m.home_county_id = c1.id
         JOIN counties c2 ON m.away_county_id = c2.id
         $whereSql
         ORDER BY m.group_label, m.match_date",
        $params
    );

    $teams = [];
    foreach ($rows as $r) {
        foreach ([
            ['id' => $r['home_county_id'], 'name' => $r['home_name'], 'group' => $r['group_label'], 'hrf' => (int)$r['home_score'], 'hra' => (int)$r['away_score']],
            ['id' => $r['away_county_id'], 'name' => $r['away_name'], 'group' => $r['group_label'], 'hrf' => (int)$r['away_score'], 'hra' => (int)$r['home_score']]
        ] as $t) {
            $tid = $t['id'];
            if (!isset($teams[$tid])) {
                $teams[$tid] = ['name' => $t['name'], 'group' => $t['group'], 'gp' => 0, 'w' => 0, 'l' => 0, 'd' => 0, 'hrf' => 0, 'hra' => 0, 'hrd' => 0, 'pts' => 0];
            }
            $teams[$tid]['gp']++;
            $teams[$tid]['hrf'] += $t['hrf'];
            $teams[$tid]['hra'] += $t['hra'];
            $teams[$tid]['hrd'] = $teams[$tid]['hrf'] - $teams[$tid]['hra'];
            if ($t['hrf'] > $t['hra']) { $teams[$tid]['w']++; $teams[$tid]['pts'] += 3; }
            elseif ($t['hrf'] === $t['hra']) { $teams[$tid]['d']++; $teams[$tid]['pts'] += 1; }
            else { $teams[$tid]['l']++; }
        }
    }

    uksort($teams, function($a, $b) use ($teams) {
        if ($teams[$a]['pts'] !== $teams[$b]['pts']) return $teams[$b]['pts'] - $teams[$a]['pts'];
        if ($teams[$a]['hrd'] !== $teams[$b]['hrd']) return $teams[$b]['hrd'] - $teams[$a]['hrd'];
        return $teams[$b]['hrf'] - $teams[$a]['hrf'];
    });

    return $teams;
}

$sports = getSports();
$counties = getCounties();
$editMatch = null;
if (isset($_GET['edit'])) {
    $editMatch = $db->fetchOne("SELECT * FROM matches WHERE id = ?", [(int)$_GET['edit']]);
}

$pageTitle = 'Manage Games';
?>
<?php include __DIR__ . '/../../templates/header.php'; ?>

<style>
.kickball-badge {
    background: #dc3545;
    color: #fff;
    font-size: 0.6rem;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 4px;
    vertical-align: middle;
    font-weight: 600;
}
.ks-table { font-size: 0.8rem; }
.ks-table th { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6c757d; border-top: none; }
.ks-table .ks-pts { font-weight: 800; color: #dc3545; font-size: 0.95rem; }
.ks-table .ks-hrd-pos { color: #28a745; font-weight: 600; }
.ks-table .ks-hrd-neg { color: #dc3545; font-weight: 600; }
.ks-table .ks-hrd-zero { color: #6c757d; }
</style>

<script>
function toggleKickballLabels() {
    var sport = document.querySelector('select[name="sport_discipline_id"]');
    var homeLabel = document.getElementById('homeScoreLabel');
    var awayLabel = document.getElementById('awayScoreLabel');
    if (sport && homeLabel && awayLabel) {
        var isKickball = parseInt(sport.value) === 2;
        homeLabel.innerHTML = isKickball ? 'Home HRF <span class="kickball-badge">HRF</span>' : 'Home Score';
        awayLabel.innerHTML = isKickball ? 'Away HRF <span class="kickball-badge">HRF</span>' : 'Away Score';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    toggleKickballLabels();
    var sportSelect = document.querySelector('select[name="sport_discipline_id"]');
    if (sportSelect) {
        sportSelect.addEventListener('change', toggleKickballLabels);
    }
});
</script>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Manage Games</h4>
    <a href="<?= APP_URL ?>pages/games/index.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1"></i>View Games</a>
</div>

<?= $msg ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><?= $editMatch ? 'Edit Match' : 'Create New Match' ?></h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editMatch ? 'update' : 'create' ?>">
                    <?php if ($editMatch): ?>
                    <input type="hidden" name="id" value="<?= $editMatch['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small">Sport</label>
                            <select name="sport_discipline_id" class="form-select form-select-sm" required>
                                <option value="">Select</option>
                                <?php foreach ($sports as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($editMatch && $editMatch['sport_discipline_id'] === $s['id']) ? 'selected' : '' ?>><?= sanitize($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small">Group</label>
                            <select name="group_label" class="form-select form-select-sm" required>
                                <option value="">-</option>
                                <?php foreach (['A','B','C','D'] as $g): ?>
                                <option value="<?= $g ?>" <?= ($editMatch && $editMatch['group_label'] === $g) ? 'selected' : '' ?>>Group <?= $g ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="scheduled" <?= ($editMatch && $editMatch['status'] === 'scheduled') ? 'selected' : '' ?>>Scheduled</option>
                                <option value="live" <?= ($editMatch && $editMatch['status'] === 'live') ? 'selected' : '' ?>>Live</option>
                                <option value="completed" <?= ($editMatch && $editMatch['status'] === 'completed') ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-5">
                            <label class="form-label small">Home Team (County)</label>
                            <select name="home_county_id" class="form-select form-select-sm" required>
                                <option value="">Select</option>
                                <?php foreach (['A','B','C','D'] as $g): ?>
                                <optgroup label="Group <?= $g ?>">
                                    <?php foreach ($counties as $c): if ($c['group_label'] !== $g) continue; ?>
                                    <option value="<?= $c['id'] ?>" <?= ($editMatch && $editMatch['home_county_id'] === $c['id']) ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-2 text-center pt-4">
                            <span class="text-muted">VS</span>
                        </div>
                        <div class="col-5">
                            <label class="form-label small">Away Team (County)</label>
                            <select name="away_county_id" class="form-select form-select-sm" required>
                                <option value="">Select</option>
                                <?php foreach (['A','B','C','D'] as $g): ?>
                                <optgroup label="Group <?= $g ?>">
                                    <?php foreach ($counties as $c): if ($c['group_label'] !== $g) continue; ?>
                                    <option value="<?= $c['id'] ?>" <?= ($editMatch && $editMatch['away_county_id'] === $c['id']) ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label class="form-label small" id="homeScoreLabel">Home Score</label>
                            <input type="number" name="home_score" class="form-control form-control-sm" placeholder="-" value="<?= $editMatch && $editMatch['home_score'] !== null ? (int)$editMatch['home_score'] : '' ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label small" id="awayScoreLabel">Away Score</label>
                            <input type="number" name="away_score" class="form-control form-control-sm" placeholder="-" value="<?= $editMatch && $editMatch['away_score'] !== null ? (int)$editMatch['away_score'] : '' ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Round</label>
                            <input type="text" name="round" class="form-control form-control-sm" value="<?= $editMatch ? sanitize($editMatch['round']) : 'Group Stage' ?>">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-8">
                            <label class="form-label small">Match Date/Time</label>
                            <input type="datetime-local" name="match_date" class="form-control form-control-sm" value="<?= $editMatch ? date('Y-m-d\TH:i', strtotime($editMatch['match_date'])) : '' ?>" required>
                        </div>
                        <div class="col-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-<?= $editMatch ? 'check' : 'plus' ?> me-1"></i><?= $editMatch ? 'Update' : 'Create' ?>
                            </button>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="1"><?= $editMatch ? sanitize($editMatch['notes'] ?? '') : '' ?></textarea>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">All Matches</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($matches)): ?>
                <div class="text-center py-4 text-muted">No matches created yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Sport</th>
                                <th>Group</th>
                                <th>Home</th>
                                <th class="text-center">Score</th>
                                <th>Away</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($matches as $m): ?>
                            <tr>
                                <td><small><?= sanitize($m['sport_name']) ?></small></td>
                                <td><span class="group-badge group-<?= $m['group_label'] ?>" style="font-size:0.65rem;"><?= $m['group_label'] ?></span></td>
                                <td><small><?= sanitize($m['home_name']) ?></small></td>
                                <td class="text-center">
                                    <?php if ($m['home_score'] !== null && $m['away_score'] !== null): ?>
                                    <strong><?= (int)$m['home_score'] ?> - <?= (int)$m['away_score'] ?></strong>
                                    <?php else: ?>
                                    <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= sanitize($m['away_name']) ?></small></td>
                                <td><small class="text-muted"><?= formatDate($m['match_date'], 'M d, h:i A') ?></small></td>
                                <td>
                                    <?php 
                                    $badge = ['scheduled' => 'secondary', 'live' => 'danger', 'completed' => 'success'];
                                    $label = ['scheduled' => 'Scheduled', 'live' => 'LIVE', 'completed' => 'Done'];
                                    ?>
                                    <span class="badge bg-<?= $badge[$m['status']] ?>"><?= $label[$m['status']] ?></span>
                                </td>
                                <td class="text-end">
                                    <a href="?edit=<?= $m['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this match?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Quick Score Entry</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">Quickly update scores on scheduled/live matches:</p>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Match</th>
                                <th class="text-center" style="width:80px;">Score / <span class="text-danger">HRF</span></th>
                                <th class="text-center" style="width:40px;">-</th>
                                <th class="text-center" style="width:80px;">Score / <span class="text-danger">HRF</span></th>
                                <th class="text-center" style="width:100px;">Status</th>
                                <th style="width:140px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $quickMatches = array_filter($matches, fn($m) => $m['status'] !== 'completed'); ?>
                            <?php if (empty($quickMatches)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-2">All matches completed.</td></tr>
                            <?php else: ?>
                            <?php foreach ($quickMatches as $m): ?>
                            <tr>
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_score">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <td><small><?= sanitize($m['home_name']) ?> vs <?= sanitize($m['away_name']) ?></small></td>
                                    <td><input type="number" name="home_score" class="form-control form-control-sm text-center" value="<?= $m['home_score'] !== null ? (int)$m['home_score'] : '' ?>" style="width:70px;"></td>
                                    <td class="text-center text-muted">-</td>
                                    <td><input type="number" name="away_score" class="form-control form-control-sm text-center" value="<?= $m['away_score'] !== null ? (int)$m['away_score'] : '' ?>" style="width:70px;"></td>
                                    <td>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="scheduled" <?= $m['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                            <option value="live" <?= $m['status'] === 'live' ? 'selected' : '' ?>>Live</option>
                                            <option value="completed" <?= $m['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                        </select>
                                    </td>
                                    <td><button type="submit" class="btn btn-sm btn-outline-success w-100"><i class="bi bi-check me-1"></i>Update</button></td>
                                </form>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-trophy me-2 text-danger"></i>Kickball Standings <span class="kickball-badge" style="font-size:0.65rem;">GP / W / L / D / HRF / HRA / HRD / PTS</span></h5>
                <a href="<?= APP_URL ?>pages/games/kickball_standings.php" class="btn btn-outline-danger btn-sm">Full Standings</a>
            </div>
            <div class="card-body p-0">
                <?php
                $kgroups = ['A','B','C','D'];
                $anyKs = false;
                foreach ($kgroups as $kg):
                    $kStands = getManageKickballStandings($db, $kg);
                    if (empty($kStands)) continue;
                    $anyKs = true;
                ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge bg-danger me-2">Group <?= $kg ?></span>
                        <small class="text-muted"><?= count($kStands) ?> teams</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm ks-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width:30px;">#</th>
                                    <th>Team</th>
                                    <th class="text-center">GP</th>
                                    <th class="text-center">W</th>
                                    <th class="text-center">L</th>
                                    <th class="text-center">D</th>
                                    <th class="text-center">HRF</th>
                                    <th class="text-center">HRA</th>
                                    <th class="text-center">HRD</th>
                                    <th class="text-center">PTS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rk = 0; foreach ($kStands as $s): $rk++; ?>
                                <tr>
                                    <td class="text-muted"><?= $rk ?></td>
                                    <td><?= sanitize($s['name']) ?></td>
                                    <td class="text-center"><?= $s['gp'] ?></td>
                                    <td class="text-center"><?= $s['w'] ?></td>
                                    <td class="text-center"><?= $s['l'] ?></td>
                                    <td class="text-center"><?= $s['d'] ?></td>
                                    <td class="text-center"><strong><?= $s['hrf'] ?></strong></td>
                                    <td class="text-center"><?= $s['hra'] ?></td>
                                    <td class="text-center <?= $s['hrd'] > 0 ? 'ks-hrd-pos' : ($s['hrd'] < 0 ? 'ks-hrd-neg' : 'ks-hrd-zero') ?>"><?= $s['hrd'] > 0 ? '+' : '' ?><?= $s['hrd'] ?></td>
                                    <td class="text-center ks-pts"><?= $s['pts'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$anyKs): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-trophy me-1"></i> No kickball standings yet — complete matches will appear here.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
