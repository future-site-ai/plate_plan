<?php
/**
 * index.php — Plate Plan · Entry Point
 *
 * Handles:
 *  - AJAX API requests (POST with `action` param)
 *  - HTML page render (GET)
 *
 * All SQL uses PDO prepared statements to prevent injection.
 */

// Composer autoloader (if packages are installed)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/request_guard.php';

// Following a link to the page is fine; AJAX calls must come from Plate Plan itself.
plate_plan_guard_request(allowNavigation: true);


/* ════════════════════════════════════════════════════════════
   AJAX ROUTER
   ════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $db     = get_db();
        $action = $_POST['action'];

        switch ($action) {

            /* ── GET ALL MEALS (with full ingredient list) ───── */
            case 'get_meals':
                // Fetch meals
                $mStmt = $db->query(
                    'SELECT m.id, m.name,
                            COUNT(i.id) AS ingredient_count
                       FROM meals m
                  LEFT JOIN ingredients i ON i.meal_id = m.id
                   GROUP BY m.id
                   ORDER BY m.name COLLATE NOCASE'
                );
                $mealsArr = $mStmt->fetchAll();

                // Attach ingredient details to each meal
                $iStmt = $db->prepare(
                    'SELECT name, quantity FROM ingredients WHERE meal_id = :mid ORDER BY name'
                );
                foreach ($mealsArr as &$meal) {
                    $iStmt->execute([':mid' => $meal['id']]);
                    $meal['ingredients'] = $iStmt->fetchAll();
                }
                unset($meal);

                echo json_encode(['meals' => $mealsArr]);
                break;

            /* ── GET ALL KNOWN INGREDIENTS (for autocomplete) ── */
            case 'get_all_ingredients':
                $stmt = $db->query(
                    'SELECT DISTINCT name FROM ingredients ORDER BY name COLLATE NOCASE'
                );
                echo json_encode(['ingredients' => array_column($stmt->fetchAll(), 'name')]);
                break;

            /* ── SAVE MEAL + INGREDIENTS ────────────────────── */
            case 'save_meal':
                $name = trim($_POST['name'] ?? '');
                if ($name === '') {
                    throw new InvalidArgumentException('Meal name is required.');
                }

                $db->beginTransaction();

                $stmt = $db->prepare('INSERT INTO meals (name) VALUES (:name)');
                $stmt->execute([':name' => $name]);
                $mealId = (int) $db->lastInsertId();

                $count = (int) ($_POST['ing_count'] ?? 0);
                $ingStmt = $db->prepare(
                    'INSERT INTO ingredients (meal_id, name, quantity)
                     VALUES (:meal_id, :name, :quantity)'
                );

                for ($i = 0; $i < $count; $i++) {
                    $ingName = trim($_POST["ing_name_{$i}"] ?? '');
                    $ingQty  = trim($_POST["ing_quantity_{$i}"] ?? '');
                    if ($ingName === '') continue;

                    $ingStmt->execute([
                        ':meal_id'  => $mealId,
                        ':name'     => $ingName,
                        ':quantity' => $ingQty,
                    ]);
                }

                $db->commit();
                echo json_encode(['success' => true, 'meal_id' => $mealId]);
                break;

            /* ── DELETE MEAL ────────────────────────────────── */
            case 'delete_meal':
                $mealId = (int) ($_POST['meal_id'] ?? 0);
                if ($mealId <= 0) throw new InvalidArgumentException('Invalid meal ID.');

                // ON DELETE CASCADE in schema handles ingredients & weekly_plan rows
                $stmt = $db->prepare('DELETE FROM meals WHERE id = :id');
                $stmt->execute([':id' => $mealId]);
                echo json_encode(['success' => true]);
                break;

            /* ── GET WEEKLY PLAN ────────────────────────────── */
            case 'get_weekly_plan':
                $stmt = $db->query(
                    'SELECT wp.id, wp.day_of_week, wp.meal_id, wp.meal_type, m.name AS meal_name
                       FROM weekly_plan wp
                       JOIN meals m ON m.id = wp.meal_id
                      ORDER BY wp.day_of_week, wp.meal_type'
                );
                echo json_encode(['plan' => $stmt->fetchAll()]);
                break;

            /* ── ADD MEAL TO PLAN ───────────────────────────── */
            case 'add_to_plan':
                $dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
                $mealId    = (int) ($_POST['meal_id']    ?? 0);
                $mealType  = trim($_POST['meal_type']    ?? '');

                $validDays  = range(1, 7);
                $validTypes = ['breakfast', 'lunch', 'dinner', 'snack'];

                if (!in_array($dayOfWeek, $validDays, true)) {
                    throw new InvalidArgumentException('Invalid day_of_week.');
                }
                if (!in_array($mealType, $validTypes, true)) {
                    throw new InvalidArgumentException('Invalid meal_type.');
                }
                if ($mealId <= 0) {
                    throw new InvalidArgumentException('Invalid meal_id.');
                }

                $stmt = $db->prepare(
                    'INSERT INTO weekly_plan (day_of_week, meal_id, meal_type)
                     VALUES (:day, :meal_id, :type)'
                );
                $stmt->execute([
                    ':day'     => $dayOfWeek,
                    ':meal_id' => $mealId,
                    ':type'    => $mealType,
                ]);
                echo json_encode(['success' => true, 'plan_id' => (int) $db->lastInsertId()]);
                break;

            /* ── MOVE PLAN ENTRY TO ANOTHER DAY ─────────────── */
            case 'move_in_plan':
                $planId    = (int) ($_POST['plan_id']     ?? 0);
                $dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);

                if ($planId <= 0) throw new InvalidArgumentException('Invalid plan_id.');
                if (!in_array($dayOfWeek, range(1, 7), true)) {
                    throw new InvalidArgumentException('Invalid day_of_week.');
                }

                $stmt = $db->prepare('UPDATE weekly_plan SET day_of_week = :day WHERE id = :id');
                $stmt->execute([':day' => $dayOfWeek, ':id' => $planId]);
                if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Plan entry not found.');
                echo json_encode(['success' => true]);
                break;

            /* ── REMOVE FROM PLAN ───────────────────────────── */
            case 'remove_from_plan':
                $planId = (int) ($_POST['plan_id'] ?? 0);
                if ($planId <= 0) throw new InvalidArgumentException('Invalid plan_id.');

                $stmt = $db->prepare('DELETE FROM weekly_plan WHERE id = :id');
                $stmt->execute([':id' => $planId]);
                echo json_encode(['success' => true]);
                break;

            /* ── CLEAR ENTIRE DAY ───────────────────────────── */
            case 'clear_day':
                $dayOfWeek = (int) ($_POST['day_of_week'] ?? 0);
                if (!in_array($dayOfWeek, range(1, 7), true)) {
                    throw new InvalidArgumentException('Invalid day_of_week.');
                }
                $stmt = $db->prepare('DELETE FROM weekly_plan WHERE day_of_week = :day');
                $stmt->execute([':day' => $dayOfWeek]);
                echo json_encode(['success' => true]);
                break;

            /* ── CLEAR WHOLE WEEK ────────────────────────────── */
            case 'clear_week':
                $db->exec('DELETE FROM weekly_plan');
                echo json_encode(['success' => true]);
                break;

            /* ── GROCERY LIST (raw rows + aggregated totals) ── */
            case 'get_grocery_list':
                // Raw rows (for the by-day breakdown)
                $rawStmt = $db->query(
                    'SELECT wp.day_of_week, wp.meal_type, m.name AS meal_name,
                            i.name AS ingredient_name, i.quantity
                       FROM weekly_plan wp
                       JOIN meals m       ON m.id = wp.meal_id
                  LEFT JOIN ingredients i ON i.meal_id = wp.meal_id
                      ORDER BY wp.day_of_week, wp.meal_type, m.name, i.name'
                );
                $rawRows = $rawStmt->fetchAll();

                // Aggregate totals: ingredient_name → {numeric_total, unit, display}
                $totals = [];
                foreach ($rawRows as $row) {
                    $ing = $row['ingredient_name'];
                    if (!$ing) continue;
                    $qty = trim((string)($row['quantity'] ?? ''));

                    // Try to split numeric part from unit, e.g. "200 g" → [200, "g"]
                    if (preg_match('/^(\d+(?:\.\d+)?)\s*(.*)$/', $qty, $m)) {
                        $num  = (float) $m[1];
                        $unit = trim($m[2]);
                    } else {
                        $num  = null;
                        $unit = $qty;
                    }

                    if (!isset($totals[$ing])) {
                        $totals[$ing] = ['numeric' => 0, 'unit' => $unit, 'has_numeric' => false, 'raw_list' => []];
                    }
                    if ($num !== null) {
                        // Only sum if units match (or first occurrence sets the unit)
                        if (!$totals[$ing]['has_numeric'] || $totals[$ing]['unit'] === $unit) {
                            $totals[$ing]['numeric']     += $num;
                            $totals[$ing]['unit']          = $unit;
                            $totals[$ing]['has_numeric']   = true;
                        } else {
                            $totals[$ing]['raw_list'][] = $qty;
                        }
                    } else {
                        $totals[$ing]['raw_list'][] = $qty ?: '1';
                    }
                }

                // Build clean totals array
                $totalsList = [];
                foreach ($totals as $name => $t) {
                    if ($t['has_numeric']) {
                        $display = $t['numeric'] . ($t['unit'] ? ' ' . $t['unit'] : '');
                        if (!empty($t['raw_list'])) {
                            $display .= ' + ' . implode(', ', array_unique($t['raw_list']));
                        }
                    } else {
                        $display = implode(', ', array_unique($t['raw_list'])) ?: '—';
                    }
                    $totalsList[] = ['name' => $name, 'total' => $display];
                }
                usort($totalsList, fn($a,$b) => strcasecmp($a['name'], $b['name']));

                echo json_encode(['grocery' => $rawRows, 'totals' => $totalsList]);
                break;

            /* ── LOAD FIXTURES ──────────────────────────────── */
            case 'load_fixtures':
                require_once __DIR__ . '/../src/fixture.php';
                // $meals is defined by fixture.php

                $db->beginTransaction();

                // Prepared statements for upsert logic
                $findMeal = $db->prepare(
                    'SELECT id FROM meals WHERE name = :name COLLATE NOCASE LIMIT 1'
                );
                $mInsert  = $db->prepare('INSERT INTO meals (name) VALUES (:name)');
                $delIngs  = $db->prepare('DELETE FROM ingredients WHERE meal_id = :mid');
                $iInsert  = $db->prepare(
                    'INSERT INTO ingredients (meal_id, name, quantity) VALUES (:mid, :name, :qty)'
                );

                $inserted = 0;
                $updated  = 0;

                foreach ($meals as $meal) {
                    // Check if a meal with this name already exists (case-insensitive)
                    $findMeal->execute([':name' => $meal['name']]);
                    $existing = $findMeal->fetch();

                    if ($existing) {
                        // UPDATE: keep the meal row, just refresh its ingredients
                        $mid = (int) $existing['id'];
                        $delIngs->execute([':mid' => $mid]);
                        $updated++;
                    } else {
                        // INSERT: brand-new meal
                        $mInsert->execute([':name' => $meal['name']]);
                        $mid = (int) $db->lastInsertId();
                        $inserted++;
                    }

                    foreach ($meal['ingredients'] as $ingName => $qty) {
                        // Convert snake_case key to a readable label
                        $readable = ucwords(str_replace('_', ' ', $ingName));
                        $iInsert->execute([':mid' => $mid, ':name' => $readable, ':qty' => (string)$qty]);
                    }
                }

                $db->commit();

                echo json_encode([
                    'success'  => true,
                    'inserted' => $inserted,
                    'updated'  => $updated,
                ]);
                break;

            /* ── AI EVALUATE WEEKLY PLAN ────────────────────── */
            case 'evaluate_weekly_plan':
            case 'evaluate_week':
                $ai = new \App\Services\AiAgentService();
                echo json_encode($ai->evaluateWeekPlan());
                break;

            /* ── AI CHATBOT ─────────────────────────────────── */
            case 'chat':
                $ai = new \App\Services\AiAgentService();
                $msg  = (string) ($_POST['message'] ?? '');
                $hist = isset($_POST['history']) ? json_decode((string)$_POST['history'], true) : [];
                echo json_encode($ai->chat($msg, is_array($hist) ? $hist : []));
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action.']);
        }

    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }

    exit;
}

/* ════════════════════════════════════════════════════════════
   HTML PAGE
   ════════════════════════════════════════════════════════════ */
// Ensure DB / schema is ready even on first GET
get_db();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Plate Plan — A beautiful weekly meal planner. Plan your meals by day, manage ingredients, and generate smart grocery lists.">
  <title>Plate Plan · Weekly Meal Planner</title>

  <!-- SortableJS CDN -->
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>

  <!-- Custom Styles -->
  <link rel="stylesheet" href="css/style.css">
</head>
<body>

<!-- ── Sidebar Overlay (mobile) ──────────────────────────── -->
<div id="sidebar-overlay"></div>

<!-- ════════════════════════════════════════════════════════
     APP SHELL
     ════════════════════════════════════════════════════════ -->
<div id="app">

  <!-- ╔══════════════════════════════════════════════════════╗
       ║  LEFT SIDEBAR — Meal Library                        ║
       ╚══════════════════════════════════════════════════════╝ -->
  <aside id="sidebar">

    <!-- Brand -->
    <div class="sidebar-header">
      <div class="brand">
        <div class="brand-icon">🥗</div>
        <span class="brand-name">Plate Plan</span>
      </div>
      <div class="brand-tagline">Your weekly nutrition companion</div>
    </div>

    <!-- Create Meal Form -->
    <div id="add-meal-form">
      <h6>Create New Meal</h6>
      <form id="meal-form" autocomplete="off">
        <input
          id="meal-name-input"
          class="form-control-dark"
          type="text"
          placeholder="e.g. Grilled Chicken Salad"
          required
          style="margin-bottom:10px"
        >

        <div id="ingredients-list" class="ingredients-container">
          <!-- Initial ingredient row (ing-name inputs get list="ing-datalist") -->
          <div class="ingredient-row">
            <input class="form-control-dark ing-name" placeholder="Ingredient" type="text"
                   list="ing-datalist" autocomplete="off">
            <input class="form-control-dark ing-qty qty-input" placeholder="Qty (e.g. 200g, 2 pcs)" type="text">
            <button type="button" class="btn-remove-ing" title="Remove row">✕</button>
          </div>
        </div>

        <!-- Autocomplete datalist – populated by JS -->
        <datalist id="ing-datalist"></datalist>

        <button type="button" id="btn-add-ingredient" class="btn-add-ing">
          + Add Ingredient
        </button>

        <button type="submit" id="btn-save-meal" class="btn-accent" style="margin-top:10px">
          Save Meal
        </button>
      </form>
    </div>

    <!-- Meal List (drag source) -->
    <div id="meal-list-wrap">
      <div class="meal-list-header">
        <div class="meal-list-title">Your Meals</div>
        <div class="meal-search-wrap">
          <span class="meal-search-icon">🔍</span>
          <input
            id="meal-search"
            class="meal-search-input"
            type="text"
            placeholder="Search meals…"
            autocomplete="off"
          >
          <button id="meal-search-clear" class="meal-search-clear" title="Clear" style="display:none">✕</button>
        </div>
      </div>
      <div id="sortable-meals">
        <!-- Populated by script.js -->
      </div>
      <div id="search-empty" class="search-empty-state" style="display:none">
        <div class="es-icon">🔎</div>
        <p>No meals match<br><strong id="search-term-label"></strong></p>
      </div>
    </div>

    <!-- Fixture Loader -->
    <div class="sidebar-footer">
      <button id="btn-fixtures" class="btn-fixture">
        🗂️ Load Fixture Meals
      </button>
    </div>

  </aside><!-- /sidebar -->

  <!-- ╔══════════════════════════════════════════════════════╗
       ║  MAIN CONTENT — Weekly Dashboard                    ║
       ╚══════════════════════════════════════════════════════╝ -->
  <main id="main">

    <!-- Top Bar -->
    <header id="topbar">
      <div style="display:flex;align-items:center;gap:12px">
        <button class="btn-menu-toggle" id="btn-menu-toggle" aria-label="Open sidebar">☰</button>
        <h1>Weekly Planner</h1>
      </div>
      <div class="topbar-right">
        <button class="btn-clear-week" id="btn-clear-week" title="Remove all meals from the week">
          🧹 Clear Week
        </button>
        <button class="btn-ai-eval" id="btn-ai-eval" title="AI Nutritional Evaluation">
          ✨ Evaluate Week
        </button>
        <button class="btn-grocery" id="btn-grocery">
          🛒 Generate Grocery List
        </button>
        <button class="btn-topbar-chat" id="btn-topbar-chat" title="Chat with AI to update & change meals in the week">
          <span class="chat-icon">💬</span>
          <span>AI Assistant</span>
          <span class="chat-pulse-badge"></span>
        </button>
      </div>
    </header>

    <!-- AI Evaluation Notice in Dashboard -->
    <div id="ai-notice-banner" class="ai-notice-banner" role="status">
      <div class="ai-notice-icon">✨</div>
      <div class="ai-notice-content">
        <div class="ai-notice-title">Plate Plan AI Nutrition & Planning Assistant</div>
        <div class="ai-notice-disclaimer">
          <strong>Notice:</strong> AI is generating nutritional evaluations and meal suggestions. AI can make mistakes — always consult a healthcare or nutrition professional for medical dietary advice.
        </div>
      </div>
      <button class="ai-notice-btn" id="btn-banner-eval">Run Evaluation</button>
    </div>

    <!-- 7-Column Week Grid -->
    <div id="week-grid">
      <!-- Columns built by script.js -->
    </div>

  </main><!-- /main -->

</div><!-- /#app -->

<!-- ════════════════════════════════════════════════════════
     MODAL — Meal Type Selection
     ════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-meal-type" role="dialog" aria-modal="true" aria-labelledby="modal-meal-type-title">
  <div class="modal-box">
    <div class="modal-title" id="modal-meal-type-title">Tag This Meal</div>
    <div class="modal-subtitle">
      Adding <strong id="modal-meal-name">—</strong> to <strong id="modal-day-name">—</strong>.<br>
      What type of meal is it?
    </div>

    <div class="meal-type-grid">
      <button class="meal-type-btn" data-type="breakfast">
        <span class="type-icon">🌅</span> Breakfast
      </button>
      <button class="meal-type-btn" data-type="lunch">
        <span class="type-icon">☀️</span> Lunch
      </button>
      <button class="meal-type-btn" data-type="dinner">
        <span class="type-icon">🌙</span> Dinner
      </button>
      <button class="meal-type-btn" data-type="snack">
        <span class="type-icon">🍎</span> Snack
      </button>
    </div>

    <div class="modal-actions">
      <button class="btn-ghost" data-close-modal="modal-meal-type">Cancel</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════
     MODAL — Grocery List
     ════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-grocery" role="dialog" aria-modal="true" aria-labelledby="modal-grocery-title">
  <div class="modal-box grocery-modal-box">
    <div class="modal-title" id="modal-grocery-title">🛒 Weekly Grocery List</div>
    <div class="modal-subtitle">All ingredients from your planned meals.</div>

    <!-- Tabs inside modal -->
    <div class="grocery-tabs">
      <button class="grocery-tab active" data-gtab="by-day">By Day</button>
      <button class="grocery-tab" data-gtab="totals">Total Needed</button>
    </div>

    <div id="grocery-list-content" class="grocery-list-content">
      <!-- Populated dynamically -->
    </div>

    <div class="modal-actions">
      <button class="btn-ghost" data-close-modal="modal-grocery">Close</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════
     MODAL — AI Week Evaluation
     ════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-evaluation" role="dialog" aria-modal="true" aria-labelledby="modal-eval-title">
  <div class="modal-box eval-modal-box">
    <div class="modal-title" id="modal-eval-title">✨ Weekly Plan AI Evaluation</div>
    <div class="ai-eval-disclaimer-badge">
      ⚠️ Notice: AI-generated evaluations may contain errors. Verify dietary and nutritional choices with a qualified health professional.
    </div>

    <div id="eval-modal-content" class="eval-modal-content">
      <!-- Populated dynamically -->
    </div>

    <div class="modal-actions">
      <button class="btn-accent" id="btn-open-chat-from-eval">Ask AI Assistant</button>
      <button class="btn-ghost" data-close-modal="modal-evaluation">Close</button>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════
     FLOATING AI CHATBOT
     ════════════════════════════════════════════════════════ -->
<!-- Bottom-Center Floating Button -->
<button id="btn-chatbot-toggle" class="btn-chatbot-toggle" aria-label="Open Plate Plan AI Assistant" title="Chat with Plate Plan AI to update & change meals">
  <span class="chatbot-toggle-icon">💬</span>
  <span class="chatbot-toggle-text">AI Meal Assistant</span>
  <span class="chatbot-pulse-dot" title="Ready"></span>
</button>

<!-- Bottom-Docked Chatbot Bar (keeps the dashboard visible) -->
<div id="chatbot-panel" class="chatbot-panel" role="region" aria-label="AI Chatbot">
  <!-- Expandable conversation history (collapsed by default) -->
  <div id="chatbot-history" class="chatbot-history">
    <div class="chatbot-header">
      <div class="chatbot-header-info">
        <div class="chatbot-avatar">🤖</div>
        <div>
          <div class="chatbot-title">Plate Plan AI Assistant</div>
          <div class="chatbot-status">
            <span class="status-indicator"></span> Ready to update & change meals
          </div>
        </div>
      </div>
      <div class="chatbot-header-actions">
        <button id="btn-chatbot-clear" class="btn-chat-icon" title="Clear conversation">🗑️</button>
        <button id="btn-chatbot-collapse" class="btn-chat-icon" title="Show less">▾</button>
      </div>
    </div>

    <!-- Messages Container -->
    <div id="chatbot-messages" class="chatbot-messages">
      <!-- Initial welcome message -->
      <div class="chat-msg chat-msg-assistant">
        <div class="chat-msg-avatar">🤖</div>
        <div class="chat-msg-body">
          <p>👋 Hello! I am your <strong>Plate Plan AI Assistant</strong>.</p>
          <p>Ask me anytime to update and change meals in your week:</p>
          <ul>
            <li>🔄 <strong>Change a meal:</strong> <em>"Change Wednesday dinner to Salmon with Rice"</em></li>
            <li>🗓️ <strong>Plan your week:</strong> <em>"Plan balanced meals for this week"</em></li>
            <li>➕ <strong>Add a meal:</strong> <em>"Add Chicken Salad to Monday lunch"</em></li>
            <li>🍳 <strong>Create a recipe:</strong> <em>"Create an Avocado Toast meal with 2 slices bread and 1 avocado"</em></li>
            <li>🗑️ <strong>Remove a meal:</strong> <em>"Remove meal from Tuesday lunch"</em> or <em>"Clear Friday"</em></li>
            <li>📊 <strong>Evaluate nutrition:</strong> <em>"Evaluate my weekly plan"</em></li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- One-line status / latest reply; click to expand the full conversation -->
  <button type="button" id="chatbot-peek" class="chatbot-peek" aria-expanded="false" aria-controls="chatbot-history">
    <span class="chatbot-peek-icon">🤖</span>
    <span id="chatbot-peek-text" class="chatbot-peek-text">Hi! Ask me to change, add, or plan meals for your week.</span>
    <span id="chatbot-peek-toggle" class="chatbot-peek-toggle">Show more ▴</span>
  </button>

  <!-- Suggested Action Chips -->
  <div class="chatbot-suggestions">
    <button class="chip" data-prompt="Plan meals for this week">🗓️ Plan This Week</button>
    <button class="chip" data-prompt="Evaluate my weekly plan">📊 Evaluate Week</button>
    <button class="chip" data-prompt="Clear Friday">🧹 Clear Friday</button>
    <button class="chip" data-prompt="Clear the whole week">🧹 Clear Week</button>
  </div>

  <!-- Chat Input Form -->
  <form id="chatbot-form" class="chatbot-form">
    <input
      type="text"
      id="chatbot-input"
      class="chatbot-input"
      placeholder="e.g. Change Wednesday dinner to Salmon with Rice..."
      autocomplete="off"
      required
    >
    <button type="submit" id="btn-chatbot-send" class="btn-chatbot-send" aria-label="Send">
      ➤
    </button>
    <button type="button" id="btn-chatbot-close" class="btn-chat-icon btn-chatbot-close" title="Close assistant">✕</button>
  </form>
</div>

<!-- ════════════════════════════════════════════════════════
     TOAST CONTAINER
     ════════════════════════════════════════════════════════ -->
<div id="toast-container" aria-live="polite" aria-atomic="true"></div>

<!-- ════════════════════════════════════════════════════════
     SCRIPTS
     ════════════════════════════════════════════════════════ -->
<script src="js/script.js"></script>

</body>
</html>
