<?php
// exercise_presets.php
declare(strict_types=1);

/**
 * Master list of exercise presets used in:
 * - trainer_workout_plan_edit.php
 * - workout_plan_detail.php
 *
 * Each preset:
 *  - name            (string)        -> must match exactly what the coach types
 *  - body_part       (string)
 *  - category        (string)        -> Machine / Free weight / Cable / Bodyweight / Mixed
 *  - primary_muscles (string)
 *  - description     (string)        -> ENGLISH ONLY
 *  - youtube_url     (string|null)   -> YouTube embed URL, or null if not set
 *
 * SECURITY NOTE:
 * This file is a "data include" and should not execute session/auth logic.
 * Pages that include it must already handle bootstrap/auth/role checks.
 */

// Block direct web access (this file should be included, not executed directly).
if (PHP_SAPI !== 'cli') {
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($script !== '' && realpath($script) === realpath(__FILE__)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

return [

    /* ============================================================
     * 1. LEGS – MACHINES
     * ============================================================ */

    [
        'name' => 'Leg Press Machine',
        'body_part' => 'Legs',
        'category' => 'Machine',
        'primary_muscles' => 'Quadriceps, glutes, hamstrings',
        'description' => 'Adjust the seat so your lower back stays pressed into the pad. Place feet shoulder-width on the platform with heels flat. Brace your core, unlock the safeties, and press the sled by extending knees and hips. Lower under control until your knees track over toes without your hips lifting or your lower back rounding. Avoid locking out hard at the top; keep constant tension.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Hack Squat Machine',
        'body_part' => 'Legs',
        'category' => 'Machine',
        'primary_muscles' => 'Quadriceps, glutes',
        'description' => 'Set shoulders/back firmly into the pads and place feet on the platform about shoulder-width. Keep ribs down and core tight. Descend by bending knees and hips, keeping knees aligned with toes and heels planted. Reach a depth you can control without your pelvis tucking excessively, then drive up through mid-foot/heels. Do not bounce at the bottom; move smoothly.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Seated Leg Curl Machine',
        'body_part' => 'Legs',
        'category' => 'Machine',
        'primary_muscles' => 'Hamstrings',
        'description' => 'Adjust the back pad so your knees line up with the machine’s pivot point. Secure the thigh pad and place the lower pad just above the heels/ankles. Curl the pad down by flexing the knees while keeping hips glued to the seat. Pause briefly to squeeze the hamstrings, then return slowly to a full stretch without letting the weight slam.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Lying Leg Curl Machine',
        'body_part' => 'Legs',
        'category' => 'Machine',
        'primary_muscles' => 'Hamstrings',
        'description' => 'Lie face down with hips pressed into the bench and knees aligned with the pivot. Place the pad just above the ankles. Curl the weight toward your glutes while keeping your pelvis down and core braced (avoid lifting the hips). Squeeze at the top, then lower under control to a full stretch without bouncing.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Leg Extension Machine',
        'body_part' => 'Legs',
        'category' => 'Machine',
        'primary_muscles' => 'Quadriceps',
        'description' => 'Adjust the seat so knees align with the pivot and the pad sits on the lower shin/ankles. Grip the handles, brace your core, and extend the knees to lift the weight smoothly. Pause briefly at the top to contract the quads, then lower slowly to a comfortable bend. Avoid swinging your torso or kicking the weight up.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Smith Machine Squats',
        'body_part' => 'Legs',
        'category' => 'Machine',
        'primary_muscles' => 'Quadriceps, glutes',
        'description' => 'Set the bar on the upper back (not the neck) and position feet so you can squat without heels lifting. Brace the core, unlock the bar, and descend by bending hips and knees with knees tracking over toes. Keep chest tall and spine neutral. Drive up through mid-foot/heels, stopping short of a hard lockout. Re-rack safely after the set.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Standing Calf Raise Machine',
        'body_part' => 'Calves',
        'category' => 'Machine',
        'primary_muscles' => 'Gastrocnemius',
        'description' => 'Stand with the balls of your feet on the platform and shoulders under the pads. Start from a deep stretch (heels lowered) while keeping knees mostly straight (soft bend is fine). Rise onto your toes as high as possible, pause to squeeze, then lower slowly back to the stretch. Avoid bouncing—use controlled tempo for full range.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Seated Calf Raise Machine',
        'body_part' => 'Calves',
        'category' => 'Machine',
        'primary_muscles' => 'Soleus',
        'description' => 'Sit with knees bent ~90 degrees and the pad resting securely on your thighs. Place the balls of your feet on the platform edge. Lower heels to a comfortable stretch, then press through the toes to raise heels high. Pause briefly at the top, then return slowly. Keep the movement at the ankle—don’t rock the hips.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Glute Kickback Machine',
        'body_part' => 'Glutes',
        'category' => 'Machine',
        'primary_muscles' => 'Gluteus maximus',
        'description' => 'Set your torso firmly into the pad/handles and align the working foot with the platform/pad. Keep a neutral spine and brace the core. Drive the leg back by extending the hip (think “push the heel behind you”), stopping before your lower back arches. Pause to squeeze the glute, then return with control to the start.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 1. LEGS – FREE / WEIGHTED
     * ============================================================ */

    [
        'name' => 'Squat (Barbell / Dumbbell)',
        'body_part' => 'Legs',
        'category' => 'Free weight',
        'primary_muscles' => 'Quadriceps, glutes, core',
        'description' => 'Set feet about shoulder-width with toes slightly out. Brace your core and keep your chest proud. Sit the hips back and down while knees track in line with toes. Descend to a depth you can control with a neutral spine, then drive up through mid-foot/heels. Keep the bar over mid-foot; avoid collapsing knees inward.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Front Squat',
        'body_part' => 'Legs',
        'category' => 'Free weight',
        'primary_muscles' => 'Quadriceps, upper back, core',
        'description' => 'Rack the bar on the front delts with elbows high and chest up. Stand tall, brace your core, and squat down with an upright torso. Keep knees tracking over toes and heels planted. Drive up through mid-foot/heels while maintaining elbow position to prevent the bar from rolling forward. Control the bottom—no bounce.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Goblet Squat',
        'body_part' => 'Legs',
        'category' => 'Free weight',
        'primary_muscles' => 'Quadriceps, glutes, core',
        'description' => 'Hold a dumbbell or kettlebell tight to your chest. Set feet shoulder-width and brace the core. Squat down between your knees with chest tall, keeping heels grounded. Reach a comfortable depth with a neutral spine, then stand by pushing the floor away. Keep the weight close—avoid leaning forward excessively.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Bulgarian Split Squat',
        'body_part' => 'Legs / Glutes',
        'category' => 'Free weight',
        'primary_muscles' => 'Glutes, quadriceps, core',
        'description' => 'Place the rear foot on a bench and set the front foot far enough forward to keep balance. Brace your core and keep hips square. Lower straight down by bending the front knee and dropping the back knee toward the floor. Drive up through the front heel/mid-foot, squeezing the glute. Avoid pushing off the back foot.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Walking Lunge',
        'body_part' => 'Legs / Glutes',
        'category' => 'Free weight',
        'primary_muscles' => 'Quadriceps, glutes, hamstrings',
        'description' => 'Stand tall and step forward into a long stride. Lower until both knees approach ~90 degrees, keeping the front knee tracking over the toes and torso upright. Push through the front heel to rise and step forward into the next rep. Keep steps controlled and stable—avoid wobbling or bouncing between strides.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Reverse Lunge',
        'body_part' => 'Legs / Glutes',
        'category' => 'Free weight',
        'primary_muscles' => 'Glutes, hamstrings, quadriceps',
        'description' => 'From standing, step back and land softly on the ball of the rear foot. Lower by bending both knees, keeping the front shin relatively vertical and chest up. Drive through the front heel to return to standing. Keep hips square and avoid letting the front knee cave inward.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Step-ups (Barbell / Dumbbell)',
        'body_part' => 'Legs / Glutes',
        'category' => 'Free weight',
        'primary_muscles' => 'Quadriceps, glutes',
        'description' => 'Choose a box height that allows control without excessive hip shift. Place the whole foot on the box, brace your core, and drive through the lead heel to stand tall on top. Control the descent by stepping down slowly (avoid jumping). Keep the knee aligned over toes and minimize pushing from the trailing leg.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Romanian Deadlift',
        'body_part' => 'Legs',
        'category' => 'Free weight',
        'primary_muscles' => 'Hamstrings, glutes, lower back',
        'description' => 'Hold the bar/dumbbells close to the body. With a slight knee bend, hinge at the hips by pushing them back while keeping a neutral spine and shoulders packed. Lower until you feel a strong hamstring stretch (usually mid-shin), then drive hips forward to stand tall and squeeze glutes. Do not round the back or turn it into a squat.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Conventional Deadlift',
        'body_part' => 'Legs / Back',
        'category' => 'Free weight',
        'primary_muscles' => 'Hamstrings, glutes, spinal erectors, traps',
        'description' => 'Set the bar over mid-foot and take a grip just outside the knees. Drop hips, brace hard, and keep a flat back with lats engaged. Push the floor away to stand, keeping the bar close to your legs. Lock out by fully extending hips (not leaning back), then lower with control by hinging first and bending knees once the bar passes them.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Single-Leg Romanian Deadlift',
        'body_part' => 'Legs / Glutes',
        'category' => 'Free weight',
        'primary_muscles' => 'Hamstrings, glutes, core stabilizers',
        'description' => 'Stand on one leg with a soft knee and brace the core. Hinge at the hip while the free leg extends behind you for balance, keeping hips square to the floor. Lower the weight with a neutral spine until you feel a hamstring stretch, then return by driving the hip forward and squeezing the glute. Avoid rotating the pelvis open.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Sumo Deadlift',
        'body_part' => 'Legs / Glutes',
        'category' => 'Free weight',
        'primary_muscles' => 'Glutes, hamstrings, adductors',
        'description' => 'Take a wide stance with toes turned out and shins close to the bar. Grip the bar inside the knees. Brace the core, keep chest up, and push the floor away while driving knees out. Stand tall by extending hips and knees together, then lower under control with the bar close to the body. Avoid rounding or jerking the start.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Glute-Ham Raise',
        'body_part' => 'Legs / Glutes',
        'category' => 'Bodyweight / Machine',
        'primary_muscles' => 'Hamstrings, glutes, lower back',
        'description' => 'Set the GHD so knees are just behind the pad and ankles are locked in. Start tall with hips extended and core braced. Lower your torso forward under control while maintaining a straight line from knees to head, then pull back up by contracting hamstrings and glutes. Keep hips from folding excessively; control the descent to protect the knees.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Pistol Squat',
        'body_part' => 'Legs',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Quadriceps, glutes, core',
        'description' => 'Balance on one foot with the other leg extended forward. Brace the core and reach arms forward as needed. Sit back and down under control, keeping the heel planted and knee tracking over toes. Descend as low as you can with good alignment, then drive up through the whole foot to stand. Use assistance (box/TRX) if form breaks.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Curtsy Lunge',
        'body_part' => 'Legs / Glutes',
        'category' => 'Free weight / Bodyweight',
        'primary_muscles' => 'Gluteus medius, gluteus maximus, quadriceps',
        'description' => 'Stand tall, then step one leg diagonally behind and across the other. Keep hips square and chest up as you bend both knees to lower under control. Push through the front heel to return to standing, squeezing the glute. Avoid twisting the knee—let the movement come from hips and controlled foot placement.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 2. GLUTES
     * ============================================================ */

    [
        'name' => 'Hip Abduction Machine',
        'body_part' => 'Glutes',
        'category' => 'Machine',
        'primary_muscles' => 'Gluteus medius, gluteus minimus',
        'description' => 'Sit upright with your back supported and feet planted. Start with knees inside the pads. Brace the core and drive knees outward in a controlled arc without leaning back to cheat. Pause briefly at peak contraction, then return slowly until you feel a stretch. Keep constant tension—avoid letting the stack slam.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Hip Adduction Machine',
        'body_part' => 'Legs / Glutes',
        'category' => 'Machine',
        'primary_muscles' => 'Hip adductors',
        'description' => 'Sit tall with back supported and legs placed against the pads. Begin with legs apart, then squeeze inward by bringing knees together under control. Pause briefly at the end range to contract the inner thighs, then return slowly to a comfortable stretch. Avoid bouncing; keep hips stable on the seat.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Smith Machine Hip Thrust',
        'body_part' => 'Glutes',
        'category' => 'Machine',
        'primary_muscles' => 'Gluteus maximus, hamstrings',
        'description' => 'Set upper back on a bench with the Smith bar padded and positioned across the hips. Feet should be about shoulder-width with shins near vertical at the top. Brace the core, drive through heels to lift hips until torso is parallel to the floor, and squeeze glutes hard. Lower under control without losing rib position or hyperextending the low back.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Cable Glute Kickback',
        'body_part' => 'Glutes',
        'category' => 'Cable',
        'primary_muscles' => 'Gluteus maximus',
        'description' => 'Attach an ankle strap to a low cable and hold the machine for balance. Brace the core and keep hips square. Extend the working leg back and slightly up by driving through the heel, stopping before your lower back arches. Pause to squeeze the glute, then return slowly until you feel a stretch. Avoid swinging—control the cable.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Barbell Hip Thrust',
        'body_part' => 'Glutes',
        'category' => 'Free weight',
        'primary_muscles' => 'Gluteus maximus, hamstrings',
        'description' => 'Place upper back on a bench and position the barbell across the hips with padding. Feet shoulder-width, toes slightly out, and core braced. Drive through heels to lift hips until shoulders-to-knees form a straight line. Tuck ribs down and squeeze glutes at the top. Lower under control without bouncing off the floor.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Glute Bridge (Bodyweight / Barbell)',
        'body_part' => 'Glutes',
        'category' => 'Bodyweight / Free weight',
        'primary_muscles' => 'Gluteus maximus, hamstrings',
        'description' => 'Lie on your back with knees bent and feet flat about hip-width. Brace the core and drive through heels to lift hips, aiming to form a straight line from shoulders to knees. Squeeze glutes at the top without over-arching the low back, then lower slowly. Add a barbell or plate as needed while maintaining control.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Single-Leg Glute Bridge',
        'body_part' => 'Glutes',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Glutes, hamstrings, core stabilizers',
        'description' => 'Set up as a glute bridge, then extend one leg while keeping thighs aligned. Drive through the planted heel to lift hips evenly, keeping pelvis level (no twisting). Pause to squeeze the working-side glute, then lower under control. Keep ribs down and avoid pushing with the extended leg.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Cable Pull-Through',
        'body_part' => 'Glutes / Hamstrings',
        'category' => 'Cable',
        'primary_muscles' => 'Gluteus maximus, hamstrings, lower back',
        'description' => 'Attach a rope to a low pulley and face away from the stack with the rope between your legs. Step forward to create tension. Hinge at the hips with a neutral spine, then drive hips forward to stand tall and squeeze glutes. Keep arms long and let hips do the work. Return by hinging back under control.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Kettlebell Swing',
        'body_part' => 'Glutes / Hamstrings',
        'category' => 'Free weight',
        'primary_muscles' => 'Glutes, hamstrings, lower back, core',
        'description' => 'Start with the kettlebell slightly in front of you. Hike it back between the legs by hinging at the hips, then explosively extend hips to swing the bell up to chest height (arms act as guides). Keep a neutral spine and braced core. Let the bell fall back into the hinge—do not squat it down or raise with the shoulders.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Donkey Kick (Bodyweight / Cable)',
        'body_part' => 'Glutes',
        'category' => 'Bodyweight / Cable',
        'primary_muscles' => 'Gluteus maximus',
        'description' => 'On all fours with hands under shoulders and knees under hips, brace the core. Keeping the knee bent, drive the heel up and back by extending the hip (avoid arching the low back). Pause to squeeze the glute, then lower slowly without resting. Add a cable/ankle strap for progressive resistance while keeping control.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Fire Hydrant (Bodyweight)',
        'body_part' => 'Glutes',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Gluteus medius, gluteus minimus',
        'description' => 'Start on all fours with a neutral spine and core braced. Keeping the knee bent ~90 degrees, lift the knee out to the side without shifting your torso or rotating the pelvis. Pause briefly at the top to feel the side glute, then lower under control. Move slowly—avoid swinging the leg.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Frog Pumps',
        'body_part' => 'Glutes',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Gluteus maximus',
        'description' => 'Lie on your back and bring the soles of your feet together with knees flared out. Keep core braced and drive hips up in short, controlled pulses, focusing on squeezing the glutes at the top. Maintain constant tension and avoid pushing through the toes. Add a dumbbell/plate on the hips for added load.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 3. SHOULDERS
     * ============================================================ */

    [
        'name' => 'Shoulder Press Machine',
        'body_part' => 'Shoulders',
        'category' => 'Machine',
        'primary_muscles' => 'Anterior and medial deltoids, triceps',
        'description' => 'Adjust the seat so handles start around ear/shoulder level. Sit tall with back against the pad, feet planted, and core braced. Press up and slightly back to align with the shoulder joint, stopping just short of a hard lockout. Lower under control to the start position without letting shoulders shrug.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Lateral Raise Machine',
        'body_part' => 'Shoulders',
        'category' => 'Machine',
        'primary_muscles' => 'Lateral deltoids',
        'description' => 'Set the seat so your upper arms are supported and elbows align with the machine’s path. Keep chest down, core braced, and shoulders “packed.” Raise arms out to the sides until roughly shoulder height, pause briefly, then lower slowly. Avoid shrugging or leaning back to move more weight.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Rear Delt Machine',
        'body_part' => 'Shoulders / Upper back',
        'category' => 'Machine',
        'primary_muscles' => 'Rear deltoids, middle trapezius, rhomboids',
        'description' => 'Sit facing the pad with chest supported and arms extended to the handles. Keep shoulders down and neck relaxed. Pull arms out and back in a wide arc, leading with elbows, until shoulder blades squeeze together. Pause briefly, then return slowly to a full stretch without letting the stack slam.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Smith Machine Overhead Press',
        'body_part' => 'Shoulders',
        'category' => 'Machine',
        'primary_muscles' => 'Deltoids, triceps, upper traps',
        'description' => 'Set the bench upright or stand with the bar at upper chest height. Grip slightly wider than shoulders, brace the core, and press the bar overhead in a controlled line. Keep ribs down and avoid excessive back arch. Lower to a comfortable range near the upper chest/clavicle, then press again smoothly.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Overhead Press (Barbell / Dumbbell)',
        'body_part' => 'Shoulders',
        'category' => 'Free weight',
        'primary_muscles' => 'Deltoids, triceps, upper traps, core',
        'description' => 'Start with weights at shoulder height and wrists stacked over elbows. Brace your core and squeeze glutes to stabilize the torso. Press overhead so the weights finish over mid-foot, then lower under control to the start. Keep the neck neutral and avoid flaring ribs or turning the movement into a standing incline press.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Arnold Press',
        'body_part' => 'Shoulders',
        'category' => 'Free weight',
        'primary_muscles' => 'Deltoids (all heads), triceps',
        'description' => 'Start seated or standing with dumbbells in front of the chest, palms facing you. As you press up, rotate palms outward so they face forward overhead. Reverse the rotation as you lower back to the start. Keep core braced and shoulders down; use a smooth rotation without jerking.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Dumbbell Lateral Raise',
        'body_part' => 'Shoulders',
        'category' => 'Free weight',
        'primary_muscles' => 'Lateral deltoids',
        'description' => 'Stand tall with a slight bend in elbows and dumbbells at your sides. Brace the core and raise arms out to the sides until about shoulder height, leading with elbows and keeping wrists neutral. Pause briefly, then lower slowly. Avoid swinging, shrugging, or leaning back for momentum.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Dumbbell Front Raise',
        'body_part' => 'Shoulders',
        'category' => 'Free weight',
        'primary_muscles' => 'Anterior deltoids',
        'description' => 'Hold dumbbells in front of thighs with a neutral grip. Brace your core and raise one or both arms straight forward to shoulder height while keeping shoulders down and torso still. Pause briefly, then lower under control. Avoid using the lower back to swing the weights upward.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Dumbbell Reverse Fly',
        'body_part' => 'Shoulders / Upper back',
        'category' => 'Free weight',
        'primary_muscles' => 'Rear deltoids, rhomboids, middle traps',
        'description' => 'Hinge at the hips with a flat back and slight bend in elbows. With dumbbells hanging under shoulders, raise arms out to the sides in a wide arc. Focus on pulling with rear delts and upper back while keeping neck relaxed. Pause briefly at the top, then lower slowly without swinging.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Upright Row (Barbell / Dumbbell / Cable)',
        'body_part' => 'Shoulders / Traps',
        'category' => 'Free weight / Cable',
        'primary_muscles' => 'Deltoids, upper trapezius',
        'description' => 'Hold the weight in front of thighs with hands about shoulder-width. Brace the core and pull straight up along the torso, leading with elbows (elbows higher than hands). Stop around upper chest height or before shoulder discomfort. Lower under control. Use moderate load and avoid excessive internal rotation if it irritates shoulders.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Face Pull (Cable)',
        'body_part' => 'Shoulders / Upper back',
        'category' => 'Cable',
        'primary_muscles' => 'Rear deltoids, middle traps, external rotators',
        'description' => 'Set a rope on a high pulley. Grab with thumbs pointing back and step away to create tension. Pull the rope toward your face while flaring elbows out and rotating hands so knuckles face behind you. Squeeze rear delts and upper back, then return slowly. Keep ribs down and avoid shrugging.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Dumbbell Shrugs',
        'body_part' => 'Traps',
        'category' => 'Free weight',
        'primary_muscles' => 'Upper trapezius',
        'description' => 'Stand tall with dumbbells at your sides and shoulders relaxed. Elevate shoulders straight up toward ears (think “up,” not “roll”), pause briefly to squeeze, then lower slowly to a full stretch. Keep neck neutral and avoid circling shoulders or using momentum.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Barbell Shrugs',
        'body_part' => 'Traps',
        'category' => 'Free weight',
        'primary_muscles' => 'Upper trapezius',
        'description' => 'Hold the bar in front of thighs with a shoulder-width grip. Brace your core and elevate shoulders straight up, pausing at the top for a strong contraction. Lower under control to a full stretch. Keep arms straight and avoid rolling the shoulders or leaning back to cheat.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 4. CHEST
     * ============================================================ */

    [
        'name' => 'Chest Press Machine',
        'body_part' => 'Chest',
        'category' => 'Machine',
        'primary_muscles' => 'Pectoralis major, triceps, anterior deltoids',
        'description' => 'Adjust the seat so handles line up with mid-chest. Set shoulder blades back and down against the pad and keep feet planted. Press handles forward smoothly until arms are nearly straight, then return under control until you feel a chest stretch. Avoid shrugging shoulders or letting elbows flare excessively.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Pec Deck Machine',
        'body_part' => 'Chest',
        'category' => 'Machine',
        'primary_muscles' => 'Pectoralis major',
        'description' => 'Sit with back supported and elbows/forearms placed on pads. Keep chest lifted and shoulders down. Bring arms together in front of the chest in a controlled “hugging” motion, squeezing pecs at the peak. Slowly open back until you feel a stretch without letting shoulders roll forward.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Incline Chest Press Machine',
        'body_part' => 'Chest',
        'category' => 'Machine',
        'primary_muscles' => 'Upper pectorals, anterior deltoids, triceps',
        'description' => 'Set the seat so the handles start near upper chest level. Retract shoulder blades and keep ribs down. Press up and forward along the machine’s path, stopping short of a hard lockout. Lower slowly to a comfortable stretch. Focus on driving with the upper chest, not shrugging into the shoulders.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Cable Chest Fly',
        'body_part' => 'Chest',
        'category' => 'Cable',
        'primary_muscles' => 'Pectoralis major',
        'description' => 'Set pulleys slightly above shoulder height (or adjust for the angle you want). Step forward to create tension with a slight bend in elbows. Bring handles together in front of the chest in a wide arc, squeezing pecs at the end range. Return slowly to a deep stretch while keeping shoulders down and chest open.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Smith Machine Bench Press',
        'body_part' => 'Chest',
        'category' => 'Machine',
        'primary_muscles' => 'Pectorals, triceps, anterior deltoids',
        'description' => 'Lie on a flat bench with eyes under the bar. Retract shoulder blades and keep feet planted. Lower the bar to mid-chest with elbows at a comfortable angle, then press up smoothly. Avoid bouncing off the chest or letting shoulders roll forward. Re-rack the bar safely after the set.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Bench Press (Barbell / Dumbbell)',
        'body_part' => 'Chest',
        'category' => 'Free weight',
        'primary_muscles' => 'Pectorals, triceps, anterior deltoids',
        'description' => 'Set shoulder blades back and down, maintain a stable upper back, and keep feet firmly planted. Lower the bar/dumbbells to the mid-chest with control, keeping wrists stacked over elbows. Press up while maintaining scapular retraction and a neutral wrist. Avoid bouncing, excessive elbow flare, or losing tightness at the bottom.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Incline Bench Press',
        'body_part' => 'Chest',
        'category' => 'Free weight',
        'primary_muscles' => 'Upper pectorals, anterior deltoids, triceps',
        'description' => 'Set bench to a moderate incline. Retract shoulder blades and keep feet planted. Lower the bar/dumbbells toward the upper chest/clavicle area under control, then press up in line with the shoulders. Keep ribs down and avoid turning it into a shoulder press by over-inclining or flaring elbows too wide.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Decline Bench Press',
        'body_part' => 'Chest',
        'category' => 'Free weight',
        'primary_muscles' => 'Lower pectorals, triceps',
        'description' => 'Secure yourself on the decline bench. Retract shoulder blades and keep wrists neutral. Lower the bar/dumbbells toward the lower chest with control, then press back up smoothly. Maintain stable shoulders and avoid bouncing. Use a spotter or safety pins when possible.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Dumbbell Fly',
        'body_part' => 'Chest',
        'category' => 'Free weight',
        'primary_muscles' => 'Pectoralis major',
        'description' => 'Lie on a flat bench with dumbbells above the chest and a soft bend in elbows. Open arms in a wide arc until you feel a stretch across the chest, keeping shoulders down and elbows slightly bent. Bring dumbbells back together by squeezing the pecs, not by straightening the elbows. Control both directions—no bouncing.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Incline Dumbbell Fly',
        'body_part' => 'Chest',
        'category' => 'Free weight',
        'primary_muscles' => 'Upper pectorals',
        'description' => 'On an incline bench, start with dumbbells above the upper chest and elbows slightly bent. Lower arms in a controlled arc until you feel an upper-chest stretch, keeping shoulders retracted. Bring dumbbells back together by contracting the chest. Avoid letting shoulders roll forward or turning it into a press.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Push-ups',
        'body_part' => 'Chest / Core',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Pectorals, triceps, anterior deltoids, core',
        'description' => 'Set hands slightly wider than shoulders and create a straight line from head to heels. Brace core and squeeze glutes. Lower chest toward the floor with elbows angled back (not straight out), then press up to full plank. Keep hips from sagging and maintain control—use knees or incline if needed.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Chest Dips',
        'body_part' => 'Chest / Triceps',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Lower pectorals, triceps, anterior deltoids',
        'description' => 'Grip parallel bars and support your body with shoulders down and core tight. Lean slightly forward to emphasize chest. Lower under control until you feel a stretch across chest/shoulders, then press back up by driving elbows down and back. Avoid shrugging or letting shoulders collapse forward; use assistance if needed.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 5. BACK
     * ============================================================ */

    [
        'name' => 'Lat Pulldown Machine',
        'body_part' => 'Back',
        'category' => 'Machine',
        'primary_muscles' => 'Latissimus dorsi, biceps, middle back',
        'description' => 'Grip the bar slightly wider than shoulders and sit tall with chest up. Pull the bar down toward the upper chest by driving elbows down and back, keeping shoulders depressed (avoid shrugging). Pause briefly, then return the bar upward under control to a full stretch without leaning excessively or using momentum.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Seated Row Machine',
        'body_part' => 'Back',
        'category' => 'Machine',
        'primary_muscles' => 'Middle back, lats, biceps',
        'description' => 'Sit with chest supported or torso tall, feet braced, and core engaged. Start with arms extended and shoulders down. Row the handles toward your torso by pulling elbows back and squeezing shoulder blades together. Pause briefly, then return slowly to a full stretch. Avoid rounding forward or jerking the weight.',
        'youtube_url' => null,
    ],
    [
        'name' => 'T-Bar Row Machine',
        'body_part' => 'Back',
        'category' => 'Machine',
        'primary_muscles' => 'Lats, rhomboids, middle traps, biceps',
        'description' => 'Set chest support if available, or hinge with a neutral spine. Grip the handles and start with arms extended. Pull the handle toward the lower chest/upper abdomen by driving elbows back. Squeeze mid-back at the top, then lower under control to a full stretch. Keep neck neutral and avoid using hip thrust to move the load.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Pull-over Machine',
        'body_part' => 'Back / Chest',
        'category' => 'Machine',
        'primary_muscles' => 'Latissimus dorsi, serratus anterior',
        'description' => 'Adjust the seat so arms start overhead with a slight elbow bend. Brace your core and keep ribs down. Pull the lever in an arc toward your torso by engaging lats (think “drive elbows down”). Pause briefly at the bottom, then return slowly to the start without letting shoulders shrug or the weight slam.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Assisted Pull-Up Machine',
        'body_part' => 'Back / Biceps',
        'category' => 'Machine',
        'primary_muscles' => 'Lats, biceps, upper back',
        'description' => 'Select assistance and place knees/feet on the pad. Grip the handles/bar and start with shoulders down. Pull up by driving elbows toward your ribs until chin approaches/clears the bar. Lower slowly to a full hang with control. Keep core tight and avoid swinging—use the assistance to maintain strict form.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Pull-up / Chin-up',
        'body_part' => 'Back / Biceps',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Latissimus dorsi, biceps, rear deltoids',
        'description' => 'Hang from the bar with a tight core and shoulders engaged (slight scapular depression). Pull your body up by driving elbows down and back until chin clears the bar, then lower under control to a full hang. Avoid swinging or kipping unless programmed. Use bands/assistance to keep reps strict.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Barbell Row',
        'body_part' => 'Back',
        'category' => 'Free weight',
        'primary_muscles' => 'Lats, rhomboids, middle traps, biceps',
        'description' => 'Hinge at the hips with a neutral spine and knees slightly bent. Keep the bar close and torso stable. Row the bar toward the lower chest/upper abdomen by driving elbows back and squeezing shoulder blades. Lower the bar under control to full arm extension without rounding. Avoid jerking or standing up between reps.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Dumbbell Row',
        'body_part' => 'Back',
        'category' => 'Free weight',
        'primary_muscles' => 'Lats, rhomboids, biceps',
        'description' => 'Support one hand and knee on a bench with a flat back. Let the dumbbell hang under the shoulder, then row toward the hip by driving elbow back and keeping shoulder down. Pause briefly at the top, then lower slowly to a full stretch. Keep torso still—avoid twisting to lift heavier.',
        'youtube_url' => null,
    ],
    [
        'name' => 'One-Arm Dumbbell Row',
        'body_part' => 'Back',
        'category' => 'Free weight',
        'primary_muscles' => 'Lats, mid-back, biceps',
        'description' => 'Set up with stable support and neutral spine. Start with arm fully extended and shoulder packed. Pull the dumbbell toward the hip (not straight up to the shoulder) to emphasize the lat, pause to squeeze, then lower slowly to a controlled stretch. Keep hips and shoulders square—minimize rotation.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Deadlift',
        'body_part' => 'Back / Legs',
        'category' => 'Free weight',
        'primary_muscles' => 'Hamstrings, glutes, spinal erectors, traps',
        'description' => 'Set the bar over mid-foot, grip firmly, and brace your core. Engage lats, keep a neutral spine, and push the floor away to lift. Stand tall by extending hips and knees together, then lower with control by hinging first and bending knees once the bar passes them. Avoid rounding and avoid yanking the bar off the floor.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Rack Pull',
        'body_part' => 'Back',
        'category' => 'Free weight',
        'primary_muscles' => 'Spinal erectors, traps, glutes',
        'description' => 'Set the bar on safeties just below or around knee height. Take a strong grip, brace the core, and pull to standing by extending hips while keeping the bar close. Pause briefly at lockout, then lower under control back to the pins. Keep spine neutral and avoid over-leaning back at the top.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Cable Row',
        'body_part' => 'Back',
        'category' => 'Cable',
        'primary_muscles' => 'Lats, rhomboids, biceps',
        'description' => 'Sit tall with feet braced and slight knee bend. Start with arms extended and shoulders down. Row the handle toward the midsection by driving elbows back and squeezing shoulder blades together. Pause briefly, then return slowly to a full stretch without rounding. Keep torso steady—avoid rocking to move weight.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Face Pull (Rear Delt Focus)',
        'body_part' => 'Shoulders / Upper back',
        'category' => 'Cable',
        'primary_muscles' => 'Rear deltoids, middle traps, external rotators',
        'description' => 'Use a rope at upper-face height. Step back to create tension and brace your core. Pull toward the face while keeping elbows high and wide, rotating so hands separate slightly at the end. Squeeze rear delts and mid-back, then return slowly. Keep shoulders down—avoid shrugging or leaning back.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Inverted Row',
        'body_part' => 'Back',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Lats, mid-back, biceps, core',
        'description' => 'Set a bar at a height you can control. Lie underneath, grip the bar, and keep body in a straight line (glutes and abs tight). Pull chest toward the bar by driving elbows back and squeezing shoulder blades. Lower slowly to full extension. Make it harder by elevating feet or lowering the bar.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 6. BICEPS
     * ============================================================ */

    [
        'name' => 'Bicep Curl Machine',
        'body_part' => 'Biceps',
        'category' => 'Machine',
        'primary_muscles' => 'Biceps brachii',
        'description' => 'Adjust the seat so elbows align with the pivot and upper arms stay supported. Start with arms extended and shoulders relaxed. Curl the handles up by flexing the elbow without lifting elbows off the pad. Pause to squeeze at the top, then lower slowly to a full stretch. Avoid using the shoulders or swinging.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Preacher Curl Machine',
        'body_part' => 'Biceps',
        'category' => 'Machine',
        'primary_muscles' => 'Biceps brachii, brachialis',
        'description' => 'Set the preacher pad so your armpits stay close to the top edge and upper arms rest firmly. Curl the handles up smoothly without letting elbows drift forward. Squeeze at the top, then lower slowly to near full extension while maintaining control. Keep wrists neutral and avoid bouncing off the bottom.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Cable Bicep Curl',
        'body_part' => 'Biceps',
        'category' => 'Cable',
        'primary_muscles' => 'Biceps brachii, brachialis',
        'description' => 'Attach a straight bar or handle to a low pulley. Stand tall with elbows close to your sides and core braced. Curl the handle toward your shoulders while keeping upper arms still. Pause briefly to contract, then lower slowly to full extension for continuous tension. Avoid leaning back or using momentum.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Barbell Curl',
        'body_part' => 'Biceps',
        'category' => 'Free weight',
        'primary_muscles' => 'Biceps brachii',
        'description' => 'Stand with feet stable and grip the bar about shoulder-width. Keep elbows pinned near your sides and core braced. Curl the bar up smoothly to upper-abdomen/chest level, squeeze, then lower slowly to full extension. Avoid swinging, excessive back lean, or letting elbows drift forward.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Dumbbell Curl',
        'body_part' => 'Biceps',
        'category' => 'Free weight',
        'primary_muscles' => 'Biceps brachii',
        'description' => 'Stand tall with dumbbells at your sides. Keep elbows close and shoulders relaxed. Curl one at a time or both together, rotating to a palms-up position as you lift. Squeeze at the top, then lower slowly to full extension. Avoid swinging the torso—use controlled tempo.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Hammer Curl',
        'body_part' => 'Biceps / Forearms',
        'category' => 'Free weight',
        'primary_muscles' => 'Brachialis, brachioradialis, biceps',
        'description' => 'Hold dumbbells with a neutral grip (palms facing each other). Keep elbows tight to the body and core braced. Curl the dumbbells upward without rotating the wrists, pause briefly, then lower slowly. Focus on smooth reps—avoid shoulder involvement or torso sway.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Incline Dumbbell Curl',
        'body_part' => 'Biceps',
        'category' => 'Free weight',
        'primary_muscles' => 'Biceps brachii (long head)',
        'description' => 'Sit on an incline bench and let arms hang straight down. Keep shoulders back and elbows slightly behind the torso. Curl the dumbbells up without moving the upper arm, squeeze at the top, then lower slowly to a full stretch. Use moderate weight—this position increases range and difficulty.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Concentration Curl',
        'body_part' => 'Biceps',
        'category' => 'Free weight',
        'primary_muscles' => 'Biceps brachii',
        'description' => 'Sit and brace your elbow against the inner thigh. Start with arm extended and shoulder relaxed. Curl the dumbbell up toward the shoulder with strict form, pause to squeeze, then lower slowly to full extension. Keep the upper arm fixed—avoid swinging or using the torso.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Zottman Curl',
        'body_part' => 'Biceps / Forearms',
        'category' => 'Free weight',
        'primary_muscles' => 'Biceps brachii, brachioradialis, forearm flexors and extensors',
        'description' => 'Curl dumbbells up with palms facing up. At the top, rotate to palms facing down, then lower slowly with the pronated grip to emphasize forearms. Rotate back to palms up at the bottom and repeat. Keep elbows close and avoid swinging—control the eccentric for maximum benefit.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Cable Curl',
        'body_part' => 'Biceps',
        'category' => 'Cable',
        'primary_muscles' => 'Biceps brachii',
        'description' => 'Face a low pulley with a handle or bar and step back slightly to keep tension. Pin elbows near your sides and brace the core. Curl to shoulder height, squeeze briefly, then return slowly to full extension without letting the stack rest. Keep shoulders down and avoid leaning back.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 7. TRICEPS
     * ============================================================ */

    [
        'name' => 'Triceps Pushdown Machine (Cable)',
        'body_part' => 'Triceps',
        'category' => 'Cable',
        'primary_muscles' => 'Triceps brachii',
        'description' => 'Set a bar or rope on a high pulley. Stand tall with elbows tucked near your ribs and shoulders down. Push the handle down by extending the elbows until arms are straight, then return slowly to about 90 degrees while keeping elbows fixed. Avoid leaning over the stack or flaring elbows outward.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Seated Triceps Extension Machine',
        'body_part' => 'Triceps',
        'category' => 'Machine',
        'primary_muscles' => 'Triceps brachii (all heads)',
        'description' => 'Adjust the seat so handles align comfortably overhead and elbows track naturally. Keep back supported and core braced. Extend elbows to press the handles upward until arms are nearly straight, then lower slowly to a controlled stretch. Avoid letting elbows flare excessively or arching the lower back.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Overhead Cable Triceps Extension',
        'body_part' => 'Triceps',
        'category' => 'Cable',
        'primary_muscles' => 'Triceps brachii (long head)',
        'description' => 'Face away from the cable with a rope attachment and step forward into a split stance. Keep elbows pointing forward/up and core braced. Extend elbows to straighten arms overhead, squeezing triceps at lockout. Return slowly to a deep stretch without moving the upper arms too much. Avoid flaring ribs or shrugging shoulders.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Triceps Dips',
        'body_part' => 'Triceps / Chest',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Triceps, lower chest, anterior deltoids',
        'description' => 'Support yourself on parallel bars with torso more upright to emphasize triceps. Keep shoulders down and core tight. Lower under control by bending elbows until you reach a comfortable depth, then press back up by extending elbows. Avoid excessive shoulder forward collapse; use assistance if shoulders become irritated.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Close-Grip Bench Press',
        'body_part' => 'Triceps / Chest',
        'category' => 'Free weight',
        'primary_muscles' => 'Triceps brachii, pectorals',
        'description' => 'Lie on a bench with hands slightly inside shoulder width. Retract shoulder blades and keep feet planted. Lower the bar to mid-chest with elbows tucked more than a standard press, then press up while keeping wrists stacked over elbows. Focus on controlled reps—avoid flaring elbows or bouncing off the chest.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Skull Crushers (Barbell / Dumbbell)',
        'body_part' => 'Triceps',
        'category' => 'Free weight',
        'primary_muscles' => 'Triceps brachii',
        'description' => 'Lie on a bench and start with arms extended above the shoulders. Keeping upper arms mostly fixed, bend at the elbows to lower the weight toward the forehead or slightly behind the head for a deeper stretch. Extend elbows to return to the top and squeeze triceps. Use a controlled tempo—avoid letting elbows flare wide.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Overhead Dumbbell Extension',
        'body_part' => 'Triceps',
        'category' => 'Free weight',
        'primary_muscles' => 'Triceps brachii (long head)',
        'description' => 'Hold one dumbbell overhead with both hands (or single-arm). Keep core braced and ribs down. Lower the dumbbell behind the head by bending elbows while keeping upper arms pointed up. Extend elbows to raise the weight back overhead and squeeze triceps. Avoid arching the lower back or letting elbows drift outward.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Kickbacks (Dumbbell / Cable)',
        'body_part' => 'Triceps',
        'category' => 'Free weight / Cable',
        'primary_muscles' => 'Triceps brachii',
        'description' => 'Hinge forward with a flat back and brace your core. Keep the upper arm tight to your side and elbow bent. Extend the elbow to straighten the arm behind you, pause to squeeze the triceps, then return slowly to the start. Keep the upper arm still—avoid swinging the shoulder.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Rope Pushdown (Cable)',
        'body_part' => 'Triceps',
        'category' => 'Cable',
        'primary_muscles' => 'Triceps brachii (lateral head, long head)',
        'description' => 'Attach a rope to a high pulley. Keep elbows pinned near your sides and shoulders down. Push the rope down and slightly apart at the bottom to fully extend the elbows and maximize contraction. Return slowly to about 90 degrees while maintaining tension. Avoid leaning forward and turning it into a bodyweight push.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 8. CORE
     * ============================================================ */

    [
        'name' => 'Ab Crunch Machine',
        'body_part' => 'Core',
        'category' => 'Machine',
        'primary_muscles' => 'Rectus abdominis',
        'description' => 'Adjust the seat so the pads sit comfortably and your hips stay anchored. Brace your core and exhale as you flex the spine, bringing ribs toward hips (not just pulling with arms). Pause to squeeze abs, then return slowly to a controlled stretch. Avoid using momentum or yanking with the shoulders.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Cable Woodchopper',
        'body_part' => 'Core',
        'category' => 'Cable',
        'primary_muscles' => 'Obliques, transverse abdominis',
        'description' => 'Set the pulley high-to-low or low-to-high depending on variation. Stand sideways to the stack with feet shoulder-width and core braced. Pull the handle diagonally across the body by rotating through the torso while keeping hips stable (or rotating hips slightly if programmed). Control the return. Avoid shrugging or letting arms dominate the movement.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Roman Chair / Hyperextension Bench',
        'body_part' => 'Lower back / Core',
        'category' => 'Machine / Bodyweight',
        'primary_muscles' => 'Erector spinae, glutes, hamstrings',
        'description' => 'Set the pad so hips are supported and you can hinge freely. Cross arms or hold a weight to the chest. Hinge forward with a neutral spine until you feel hamstrings/glutes stretch, then extend back up by driving hips into the pad and squeezing glutes. Stop when your body is in line—avoid hyperextending the lower back.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Captain’s Chair',
        'body_part' => 'Core',
        'category' => 'Machine / Bodyweight',
        'primary_muscles' => 'Lower abdominals, hip flexors',
        'description' => 'Support your forearms on the pads with shoulders down and core tight. Raise knees toward the chest by curling the pelvis upward (posterior tilt) rather than just swinging legs. Pause briefly, then lower slowly until hips are fully extended. Avoid excessive swinging—use controlled tempo for true abdominal work.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Plank',
        'body_part' => 'Core',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Rectus abdominis, transverse abdominis, shoulders, glutes',
        'description' => 'Set elbows under shoulders and create a straight line from head to heels. Brace abs as if preparing for a punch, squeeze glutes, and keep ribs down. Hold with steady breathing while keeping hips level (no sagging or piking). Stop the set when form breaks—quality beats time.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Side Plank',
        'body_part' => 'Core',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Obliques, gluteus medius, shoulder stabilizers',
        'description' => 'Support on one forearm with elbow under shoulder and feet stacked or staggered. Lift hips so body forms a straight line from head to heels. Brace core and squeeze glutes, keeping shoulders and hips stacked. Hold with controlled breathing and avoid rotating forward/back. Modify by dropping the bottom knee if needed.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Hanging Leg Raise',
        'body_part' => 'Core',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Lower abdominals, hip flexors, grip',
        'description' => 'Hang from a bar with shoulders engaged (avoid dead-hang shrug). Brace core and lift knees or straight legs by curling the pelvis upward, keeping movement controlled. Pause briefly near the top, then lower slowly without swinging. If you swing, reduce range or switch to knee raises.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Weighted Sit-up',
        'body_part' => 'Core',
        'category' => 'Free weight / Bodyweight',
        'primary_muscles' => 'Rectus abdominis, hip flexors',
        'description' => 'Lie on your back with knees bent and feet anchored if needed. Hold a plate/dumbbell at the chest or overhead (harder). Exhale and sit up by flexing through the trunk, then lower slowly with control. Keep tension in the abs and avoid yanking the neck. Use moderate load to protect the lower back.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Russian Twist (Dumbbell / Medicine Ball)',
        'body_part' => 'Core',
        'category' => 'Free weight / Bodyweight',
        'primary_muscles' => 'Obliques, rectus abdominis',
        'description' => 'Sit with torso leaned back slightly and spine tall. Brace the core, hold the weight close to the chest, and rotate the torso side to side with control. Keep hips stable and move through the ribs/waist, not just swinging arms. Lift feet only if you can keep posture—avoid rounding the lower back.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Bicycle Crunch',
        'body_part' => 'Core',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Rectus abdominis, obliques, hip flexors',
        'description' => 'Lie on your back with hands lightly supporting the head (no pulling). Lift shoulders off the floor and brace the abs. Alternate bringing opposite elbow toward knee while extending the other leg. Rotate through the torso and keep lower back pressed down. Move with control—avoid rushing and losing form.',
        'youtube_url' => null,
    ],
    [
        'name' => 'V-Up',
        'body_part' => 'Core',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Rectus abdominis, hip flexors',
        'description' => 'Start lying flat with arms overhead and legs straight. Brace core and lift torso and legs simultaneously to meet in a “V,” reaching hands toward feet. Pause briefly, then lower slowly without fully relaxing on the floor. Keep movement smooth and controlled—bend knees slightly if flexibility limits form.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Mountain Climbers',
        'body_part' => 'Core / Conditioning',
        'category' => 'Bodyweight',
        'primary_muscles' => 'Core, hip flexors, shoulders, cardiovascular system',
        'description' => 'Start in a strong plank with hands under shoulders and core braced. Drive one knee toward the chest, then switch legs in a controlled running rhythm. Keep hips level and shoulders stable, minimizing bouncing. Maintain steady breathing and focus on quality position before speed.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Ab Rollout (Wheel / Barbell)',
        'body_part' => 'Core',
        'category' => 'Free weight / Bodyweight',
        'primary_muscles' => 'Rectus abdominis, transverse abdominis, lats, shoulders',
        'description' => 'From knees (or feet if advanced), grip the wheel/barbell and brace the core with ribs down. Roll forward slowly by extending shoulders while keeping hips from sagging and spine neutral. Go only as far as you can maintain tension, then pull back by engaging abs and lats. Avoid lower-back arching—shorten range if needed.',
        'youtube_url' => null,
    ],

    /* ============================================================
     * 9. CALVES
     * ============================================================ */

    [
        'name' => 'Leg Press Calf Raise',
        'body_part' => 'Calves',
        'category' => 'Machine',
        'primary_muscles' => 'Gastrocnemius, soleus',
        'description' => 'Sit in the leg press and place only the balls of your feet on the lower edge of the platform. Keep knees mostly straight but not locked. Lower heels to a deep stretch, then press through the toes to raise heels as high as possible. Pause to squeeze, then return slowly. Avoid bouncing or letting the sled slam.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Standing Dumbbell Calf Raise',
        'body_part' => 'Calves',
        'category' => 'Free weight',
        'primary_muscles' => 'Gastrocnemius',
        'description' => 'Hold dumbbells at your sides and stand tall. Keep core braced and knees mostly straight. Rise onto the balls of your feet as high as possible, pause briefly, then lower slowly to a full stretch with heels down. Use a step for increased range if balance allows; avoid bouncing for best calf activation.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Single-Leg Calf Raise',
        'body_part' => 'Calves',
        'category' => 'Bodyweight / Free weight',
        'primary_muscles' => 'Gastrocnemius, soleus',
        'description' => 'Stand on one foot (use a wall/rail lightly for balance). Lower heel to a full stretch (ideally on a step), then press up onto the toes as high as possible. Pause to squeeze, then lower slowly. Add a dumbbell in the opposite hand for more load. Keep the ankle movement controlled—no bouncing.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Donkey Calf Raise',
        'body_part' => 'Calves',
        'category' => 'Free weight / Machine',
        'primary_muscles' => 'Gastrocnemius',
        'description' => 'Hinge at the hips with a flat back and support hands on a stable surface, or use a donkey machine. Keep knees slightly bent and core braced. Lower heels to a full stretch, then rise onto toes as high as possible. Pause to squeeze, then return slowly. Maintain steady tempo and avoid bouncing.',
        'youtube_url' => null,
    ],
    [
        'name' => 'Jump Rope',
        'body_part' => 'Calves / Conditioning',
        'category' => 'Bodyweight / Conditioning',
        'primary_muscles' => 'Calves, foot muscles, cardiovascular system',
        'description' => 'Hold the rope handles by your sides and turn the rope primarily with the wrists. Perform small, quick jumps, landing softly on the balls of your feet with heels hovering lightly. Keep posture tall and core engaged. Start with manageable intervals and progress duration or speed while maintaining smooth rhythm and quiet landings.',
        'youtube_url' => null,
    ],

];
