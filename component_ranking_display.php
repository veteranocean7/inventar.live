<?php
/**
 * Component pentru afișarea ranking-ului utilizatorilor
 * Poate fi inclus în diferite părți ale aplicației
 */

function getRankingBadge($nivel) {
    $badges = [
        'diamond' => '💎',
        'platinum' => '🏆', 
        'gold' => '🥇',
        'silver' => '🥈',
        'bronze' => '🥉'
    ];
    return $badges[$nivel] ?? '🥉';
}

function getRankingColor($nivel) {
    $colors = [
        'diamond' => 'linear-gradient(135deg, #b3e5fc, #81d4fa)',
        'platinum' => 'linear-gradient(135deg, #e1bee7, #ba68c8)',
        'gold' => 'linear-gradient(135deg, #fff9c4, #ffd54f)',
        'silver' => 'linear-gradient(135deg, #f5f5f5, #bdbdbd)',
        'bronze' => 'linear-gradient(135deg, #ffccbc, #ff8a65)'
    ];
    return $colors[$nivel] ?? $colors['bronze'];
}

function afiseazaRankingCompact($id_utilizator, $conn_central, $show_name = false) {
    // Obține ranking-ul utilizatorului
    $sql = "SELECT r.*, 
            CONCAT(u.prenume, ' ', LEFT(u.nume, 1), '.') as nume_afisat
            FROM user_rankings r
            JOIN utilizatori u ON r.id_utilizator = u.id_utilizator
            WHERE r.id_utilizator = ?";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_utilizator);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $ranking = mysqli_fetch_assoc($result);
    
    if (!$ranking) {
        // Utilizator nou fără ranking încă
        return '<span class="ranking-compact" style="display: inline-flex; align-items: center; gap: 5px; 
                padding: 3px 8px; border-radius: 15px; font-size: 12px; 
                background: linear-gradient(135deg, #f5f5f5, #e0e0e0);">
                <span style="font-size: 14px;">🆕</span>
                <span style="color: #666;">Utilizator nou</span>
            </span>';
    }
    
    $badge = getRankingBadge($ranking['nivel_ranking']);
    $color = getRankingColor($ranking['nivel_ranking']);
    $scor = round($ranking['scor_total']);
    
    $html = '<span class="ranking-compact" style="display: inline-flex; align-items: center; gap: 5px; 
                padding: 3px 8px; border-radius: 15px; font-size: 12px; 
                background: ' . $color . '; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
    
    $html .= '<span style="font-size: 14px;">' . $badge . '</span>';
    
    if ($show_name) {
        $html .= '<span style="font-weight: 600; color: #333;">' . htmlspecialchars($ranking['nume_afisat']) . '</span>';
    }
    
    $html .= '<span style="color: #444; font-weight: 500;">' . $scor . 'p</span>';
    
    // Tooltip cu detalii
    $html .= '<span class="ranking-tooltip" style="display: none; position: absolute; 
                background: white; border: 1px solid #ddd; border-radius: 8px; 
                padding: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); 
                z-index: 1000; min-width: 200px; top: 100%; left: 0; margin-top: 5px;">
                <strong>' . htmlspecialchars($ranking['nume_afisat']) . '</strong><br>
                <div style="margin-top: 5px; font-size: 11px; color: #666;">
                    📤 Disponibilitate: ' . round($ranking['scor_disponibilitate']) . 'p<br>
                    📥 Credibilitate: ' . round($ranking['scor_credibilitate']) . 'p<br>
                    <hr style="margin: 5px 0; border: none; border-top: 1px solid #eee;">
                    Împrumuturi: ' . $ranking['total_imprumuturi'] . '<br>
                    La timp: ' . $ranking['returnate_la_timp'] . '<br>
                    Aprobări: ' . $ranking['cereri_aprobate'] . '/' . $ranking['total_cereri_primite'] . '
                </div>
            </span>';
    
    $html .= '</span>';
    
    // Script pentru tooltip
    $html .= '<script>
        document.querySelectorAll(".ranking-compact").forEach(el => {
            el.style.position = "relative";
            el.style.cursor = "help";
            
            el.addEventListener("mouseenter", function() {
                const tooltip = this.querySelector(".ranking-tooltip");
                if (tooltip) tooltip.style.display = "block";
            });
            
            el.addEventListener("mouseleave", function() {
                const tooltip = this.querySelector(".ranking-tooltip");
                if (tooltip) tooltip.style.display = "none";
            });
        });
    </script>';
    
    return $html;
}

function afiseazaRankingDetaliat($id_utilizator, $conn_central) {
    // Obține ranking-ul utilizatorului
    $sql = "SELECT r.*, 
            CONCAT(u.prenume, ' ', LEFT(u.nume, 1), '.') as nume_afisat,
            u.avatar
            FROM user_rankings r
            JOIN utilizatori u ON r.id_utilizator = u.id_utilizator
            WHERE r.id_utilizator = ?";
    
    $stmt = mysqli_prepare($conn_central, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_utilizator);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $ranking = mysqli_fetch_assoc($result);
    
    if (!$ranking) {
        return '<div class="ranking-detaliat" style="padding: 15px; background: #f5f5f5; 
                border-radius: 10px; text-align: center; color: #666;">
                <p>Încă nu ai un ranking. Începe să împrumuți și să partajezi obiecte!</p>
            </div>';
    }
    
    $badge = getRankingBadge($ranking['nivel_ranking']);
    $color = getRankingColor($ranking['nivel_ranking']);
    
    $html = '<div class="ranking-detaliat" style="padding: 20px; background: white; 
                border-radius: 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">';
    
    // Header cu nivel și badge
    $html .= '<div style="text-align: center; margin-bottom: 20px;">
                <div style="font-size: 48px; margin-bottom: 10px;">' . $badge . '</div>
                <h3 style="margin: 0; color: #333; text-transform: uppercase; letter-spacing: 2px;">
                    ' . ucfirst($ranking['nivel_ranking']) . '
                </h3>
                <div style="font-size: 24px; font-weight: bold; color: #667eea; margin-top: 10px;">
                    ' . round($ranking['scor_total']) . ' puncte
                </div>
            </div>';
    
    // Scoruri detaliate
    $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">';
    
    // Scor disponibilitate
    $html .= '<div style="background: linear-gradient(135deg, #e3f2fd, #bbdefb); 
                padding: 15px; border-radius: 10px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                    📤 DISPONIBILITATE
                </div>
                <div style="font-size: 20px; font-weight: bold; color: #1976d2;">
                    ' . round($ranking['scor_disponibilitate']) . 'p
                </div>
                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                    Aprobări: ' . $ranking['cereri_aprobate'] . '/' . $ranking['total_cereri_primite'] . '
                </div>
            </div>';
    
    // Scor credibilitate
    $html .= '<div style="background: linear-gradient(135deg, #f3e5f5, #e1bee7); 
                padding: 15px; border-radius: 10px;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">
                    📥 CREDIBILITATE
                </div>
                <div style="font-size: 20px; font-weight: bold; color: #7b1fa2;">
                    ' . round($ranking['scor_credibilitate']) . 'p
                </div>
                <div style="font-size: 11px; color: #666; margin-top: 5px;">
                    La timp: ' . $ranking['returnate_la_timp'] . '/' . $ranking['total_imprumuturi'] . '
                </div>
            </div>';
    
    $html .= '</div>';
    
    // Statistici
    $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 10px;">
                <h4 style="margin: 0 0 10px 0; color: #666; font-size: 12px; text-transform: uppercase;">
                    Statistici
                </h4>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; font-size: 13px;">';
    
    $stats = [
        'Total împrumuturi' => $ranking['total_imprumuturi'],
        'Returnate la timp' => $ranking['returnate_la_timp'],
        'Cu întârziere' => $ranking['returnate_cu_intarziere'],
        'Cereri aprobate' => $ranking['cereri_aprobate']
    ];
    
    foreach ($stats as $label => $value) {
        $html .= '<div>
                    <span style="color: #999;">' . $label . ':</span>
                    <strong style="color: #333;">' . $value . '</strong>
                </div>';
    }
    
    $html .= '</div></div>';
    
    // Ultima actualizare
    $html .= '<div style="text-align: center; margin-top: 15px; font-size: 11px; color: #999;">
                Ultima actualizare: ' . date('d.m.Y H:i', strtotime($ranking['ultima_actualizare'])) . '
            </div>';
    
    $html .= '</div>';
    
    return $html;
}
?>