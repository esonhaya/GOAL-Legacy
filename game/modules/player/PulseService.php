<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Core\Persistence\SchemaInitializationGuard;
use Goal\Legacy\Modules\Match\Domain\GameMatch;
use Goal\Legacy\Modules\Match\Domain\PlayerMatchStat;
use Goal\Legacy\Modules\Match\MatchStoryService;
use Goal\Legacy\Modules\Match\Persistence\MatchHighlightRepository;
use Goal\Legacy\Modules\Player\Domain\PlayerId;
use Goal\Legacy\Modules\Player\Persistence\CareerPlayerRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use PDO;

/**
 * Controlled-career presentation state for the fictional PULSE platform.
 * Football systems own facts; Echo selects public moments; this service
 * stores bounded sources and deterministic presentation artifacts.
 */
final class PulseService
{
    private const SOURCES = 'pulse_feed_sources';
    private const POSTS = 'pulse_posts';
    private const THREAD_EDGES = 'pulse_thread_edges';
    private const AUDIENCE = 'pulse_player_states';
    private const RESPONSES = 'pulse_response_states';
    private const MAX_SOURCES = 200;
    private const MAX_THREAD_DEPTH = 2;

    /** @var list<array{id:string,name:string,handle:string,voice:string,culture:string,nation:string,club:?string}> */
    private const IDENTITY_CATALOG = [
        ['id' => 'pulse:mara-stand', 'name' => 'Mara Stone', 'handle' => 'marastand', 'voice' => 'supportive_fan', 'culture' => 'england', 'nation' => 'england', 'club' => 'arsenal'],
        ['id' => 'pulse:tom-terrace', 'name' => 'Tom Mercer', 'handle' => 'tomterrace', 'voice' => 'old_school_fan', 'culture' => 'england', 'nation' => 'england', 'club' => 'chelsea'],
        ['id' => 'pulse:ellie-away', 'name' => 'Ellie Ward', 'handle' => 'ellieaway', 'voice' => 'reactionary_fan', 'culture' => 'england', 'nation' => 'england', 'club' => null],
        ['id' => 'pulse:owen-tactics', 'name' => 'Owen Clarke', 'handle' => 'owentactics', 'voice' => 'tactical_fan', 'culture' => 'england', 'nation' => 'england', 'club' => null],
        ['id' => 'pulse:lucia-futbol', 'name' => 'Lucía Vidal', 'handle' => 'luciafutbol', 'voice' => 'supportive_fan', 'culture' => 'spain', 'nation' => 'spain', 'club' => 'real-madrid'],
        ['id' => 'pulse:dani-tactico', 'name' => 'Dani Costa', 'handle' => 'danitactico', 'voice' => 'tactical_fan', 'culture' => 'spain', 'nation' => 'spain', 'club' => 'barcelona'],
        ['id' => 'pulse:lena-kurve', 'name' => 'Lena Vogt', 'handle' => 'lenakurve', 'voice' => 'old_school_fan', 'culture' => 'germany', 'nation' => 'germany', 'club' => 'bayern-munich'],
        ['id' => 'pulse:jonas-press', 'name' => 'Jonas Keller', 'handle' => 'jonaspress', 'voice' => 'tactical_fan', 'culture' => 'germany', 'nation' => 'germany', 'club' => null],
        ['id' => 'pulse:chiara-calcio', 'name' => 'Chiara Riva', 'handle' => 'chiaracalcio', 'voice' => 'supportive_fan', 'culture' => 'italy', 'nation' => 'italy', 'club' => 'juventus'],
        ['id' => 'pulse:marco-tribuna', 'name' => 'Marco Bellini', 'handle' => 'marcotribuna', 'voice' => 'tactical_fan', 'culture' => 'italy', 'nation' => 'italy', 'club' => null],
        ['id' => 'pulse:ines-foot', 'name' => 'Inès Laurent', 'handle' => 'inesfoot', 'voice' => 'casual_fan', 'culture' => 'france', 'nation' => 'france', 'club' => 'psg'],
        ['id' => 'pulse:luc-banc', 'name' => 'Luc Moreau', 'handle' => 'lucbanc', 'voice' => 'tactical_fan', 'culture' => 'france', 'nation' => 'france', 'club' => null],
        ['id' => 'pulse:bia-futebol', 'name' => 'Bia Rocha', 'handle' => 'biafutebol', 'voice' => 'meme_account', 'culture' => 'brazil', 'nation' => 'brazil', 'club' => 'flamengo'],
        ['id' => 'pulse:caio-dez', 'name' => 'Caio Mendes', 'handle' => 'caiodez', 'voice' => 'supportive_fan', 'culture' => 'brazil', 'nation' => 'brazil', 'club' => null],
        ['id' => 'pulse:sol-hincha', 'name' => 'Sol Ferreyra', 'handle' => 'solhincha', 'voice' => 'optimistic_fan', 'culture' => 'argentina', 'nation' => 'argentina', 'club' => 'boca-juniors'],
        ['id' => 'pulse:nico-pasion', 'name' => 'Nico Acosta', 'handle' => 'nicopasion', 'voice' => 'reactionary_fan', 'culture' => 'argentina', 'nation' => 'argentina', 'club' => null],
        ['id' => 'pulse:ines-bola', 'name' => 'Inês Silva', 'handle' => 'inesbola', 'voice' => 'casual_fan', 'culture' => 'portugal', 'nation' => 'portugal', 'club' => null],
        ['id' => 'pulse:rui-linha', 'name' => 'Rui Matos', 'handle' => 'ruilinha', 'voice' => 'tactical_fan', 'culture' => 'portugal', 'nation' => 'portugal', 'club' => null],
        ['id' => 'pulse:fem-oranje', 'name' => 'Fem de Boer', 'handle' => 'femoranje', 'voice' => 'supportive_fan', 'culture' => 'netherlands', 'nation' => 'netherlands', 'club' => null],
        ['id' => 'pulse:daan-press', 'name' => 'Daan Visser', 'handle' => 'daanpress', 'voice' => 'tactical_fan', 'culture' => 'netherlands', 'nation' => 'netherlands', 'club' => null],
        ['id' => 'pulse:noor-stand', 'name' => 'Noor Peeters', 'handle' => 'noorstand', 'voice' => 'optimistic_fan', 'culture' => 'belgium', 'nation' => 'belgium', 'club' => null],
        ['id' => 'pulse:mathis-ball', 'name' => 'Mathis De Smet', 'handle' => 'mathisball', 'voice' => 'casual_fan', 'culture' => 'belgium', 'nation' => 'belgium', 'club' => null],
        ['id' => 'pulse:iva-match', 'name' => 'Iva Kovač', 'handle' => 'ivamatch', 'voice' => 'supportive_fan', 'culture' => 'croatia', 'nation' => 'croatia', 'club' => null],
        ['id' => 'pulse:mateo-tribina', 'name' => 'Mateo Jurić', 'handle' => 'mateotribina', 'voice' => 'reactionary_fan', 'culture' => 'croatia', 'nation' => 'croatia', 'club' => null],
        ['id' => 'pulse:adaeze-ball', 'name' => 'Adaeze Okafor', 'handle' => 'adaezeball', 'voice' => 'supportive_fan', 'culture' => 'nigeria', 'nation' => 'nigeria', 'club' => null],
        ['id' => 'pulse:tunde-pitch', 'name' => 'Tunde Adebayo', 'handle' => 'tundepitch', 'voice' => 'meme_account', 'culture' => 'nigeria', 'nation' => 'nigeria', 'club' => null],
        ['id' => 'pulse:ama-footy', 'name' => 'Ama Mensah', 'handle' => 'amafooty', 'voice' => 'optimistic_fan', 'culture' => 'ghana', 'nation' => 'ghana', 'club' => null],
        ['id' => 'pulse:kojo-match', 'name' => 'Kojo Asare', 'handle' => 'kojomatch', 'voice' => 'casual_fan', 'culture' => 'ghana', 'nation' => 'ghana', 'club' => null],
        ['id' => 'pulse:salma-foot', 'name' => 'Salma Idrissi', 'handle' => 'salmafoot', 'voice' => 'supportive_fan', 'culture' => 'morocco', 'nation' => 'morocco', 'club' => null],
        ['id' => 'pulse:yassin-kora', 'name' => 'Yassin El Amrani', 'handle' => 'yassinkora', 'voice' => 'reactionary_fan', 'culture' => 'morocco', 'nation' => 'morocco', 'club' => null],
        ['id' => 'pulse:aiko-pitch', 'name' => 'Aiko Mori', 'handle' => 'aikopitch', 'voice' => 'tactical_fan', 'culture' => 'japan', 'nation' => 'japan', 'club' => null],
        ['id' => 'pulse:ren-match', 'name' => 'Ren Takahashi', 'handle' => 'renmatch', 'voice' => 'casual_fan', 'culture' => 'japan', 'nation' => 'japan', 'club' => null],
        ['id' => 'pulse:minji-foot', 'name' => 'Minji Han', 'handle' => 'minjifoot', 'voice' => 'optimistic_fan', 'culture' => 'south-korea', 'nation' => 'south-korea', 'club' => null],
        ['id' => 'pulse:jihoon-game', 'name' => 'Jihoon Park', 'handle' => 'jihoongame', 'voice' => 'tactical_fan', 'culture' => 'south-korea', 'nation' => 'south-korea', 'club' => null],
        ['id' => 'pulse:bea-football', 'name' => 'Bea Santos', 'handle' => 'beafootball', 'voice' => 'supportive_fan', 'culture' => 'philippines', 'nation' => 'philippines', 'club' => null],
        ['id' => 'pulse:miguel-laro', 'name' => 'Miguel Laro', 'handle' => 'miguellaro', 'voice' => 'meme_account', 'culture' => 'philippines', 'nation' => 'philippines', 'club' => null],
        ['id' => 'pulse:sofia-cancha', 'name' => 'Sofía Reyes', 'handle' => 'sofiacancha', 'voice' => 'supportive_fan', 'culture' => 'mexico', 'nation' => 'mexico', 'club' => null],
        ['id' => 'pulse:mateo-balon', 'name' => 'Mateo Cruz', 'handle' => 'mateobalon', 'voice' => 'reactionary_fan', 'culture' => 'mexico', 'nation' => 'mexico', 'club' => null],
        ['id' => 'pulse:jordan-football', 'name' => 'Jordan Lee', 'handle' => 'jordanfootball', 'voice' => 'neutral_viewer', 'culture' => 'united-states', 'nation' => 'united-states', 'club' => null],
        ['id' => 'pulse:casey-global', 'name' => 'Casey Morgan', 'handle' => 'caseyglobal', 'voice' => 'neutral_viewer', 'culture' => 'global', 'nation' => 'global', 'club' => null],
    ];

    /** @var array<string, array<string, list<array{family:string,opening:string,slang:string,emoji:string,native:bool,reference:string,text:string}>>> */
    private const CULTURE_REACTIONS = [
        'england' => [
            'positive' => [['family' => 'away_end', 'opening' => 'proper', 'slang' => 'proper', 'emoji' => '', 'native' => false, 'reference' => 'PROPER_FOOTBALL', 'text' => 'Proper shift from {player}; the away end will be singing that one.'], ['family' => 'football_weather', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'AWAY_DAYS', 'text' => 'Rain, pressure, and a footballer who still wants the ball. {player} was class.']],
            'negative' => [['family' => 'english_standards', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'PROPER_FOOTBALL', 'text' => 'That was not good enough for a side with ambitions. Back to the training ground.'], ['family' => 'away_day_frustration', 'opening' => 'fragment', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'AWAY_DAYS', 'text' => 'Long way home after that. The result did not match the effort.']],
            'major' => [['family' => 'big_night', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'AWAY_END', 'text' => 'That is what a big night is for. {player} gave the supporters a memory.']],
        ],
        'spain' => [
            'positive' => [['family' => 'futbol_technical', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'FUTBOL', 'text' => 'The touch, the timing, the calm. {player} understood the fútbol tonight.'], ['family' => 'iberian_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'FUTBOL', 'text' => 'Qué jugador. {player} made that look much easier than it was.']],
            'negative' => [['family' => 'technical_frustration', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'TECHNICAL_FOOTBALL', 'text' => 'Too many simple decisions went wrong. The football was not clean enough.']],
            'major' => [['family' => 'futbol_moment', 'opening' => 'interjection', 'slang' => '', 'emoji' => '❤️', 'native' => true, 'reference' => 'FUTBOL', 'text' => 'Qué momento. {player} brought fútbol to the biggest stage.']],
        ],
        'germany' => [
            'positive' => [['family' => 'kurve_pride', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'SUPPORTER_CULTURE', 'text' => 'The work was clear from the first whistle. {player} gave the supporters a complete performance.'], ['family' => 'direct_football', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'DIRECT_FOOTBALL', 'text' => 'No unnecessary drama: win the duel, make the right pass, finish the move. {player} did all three.']],
            'negative' => [['family' => 'defensive_discipline', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'TACTICAL_DISCIPLINE', 'text' => 'The structure disappeared when the match needed it most. That cannot happen again.']],
            'major' => [['family' => 'kurve_moment', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'SUPPORTER_CULTURE', 'text' => 'A proper matchday memory. {player} was decisive when the whole ground was waiting.']],
        ],
        'italy' => [
            'positive' => [['family' => 'calcio_reading', 'opening' => 'analysis', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'CALCIO', 'text' => 'That was calcio intelligence: read the space, wait for the moment, punish it.'], ['family' => 'italian_praise', 'opening' => 'interjection', 'slang' => '', 'emoji' => '❤️', 'native' => true, 'reference' => 'CALCIO', 'text' => 'Che giocatore. {player} made the whole move feel inevitable.']],
            'negative' => [['family' => 'tactical_regret', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'CALCIO_TACTICS', 'text' => 'The tactical detail was missing: too open, too rushed, and punished at once.']],
            'major' => [['family' => 'calcio_big_moment', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'CALCIO', 'text' => 'Big-match calcio rewards players who can stay calm. {player} stayed calm.']],
        ],
        'france' => [
            'positive' => [['family' => 'french_fluency', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'FOOTBALL_FLUENCY', 'text' => 'The movement was elegant and the decision was ruthless. {player} changed the match.'], ['family' => 'french_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'FOOTBALL_FLUENCY', 'text' => 'Mais oui. That is exactly how you take a big chance.']],
            'negative' => [['family' => 'french_frustration', 'opening' => 'question', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'FOOTBALL_FLUENCY', 'text' => 'How did the team lose control of a match that was there to be won?']],
            'major' => [['family' => 'french_big_stage', 'opening' => 'statement', 'slang' => '', 'emoji' => '❤️', 'native' => false, 'reference' => 'FOOTBALL_FLUENCY', 'text' => 'The big stage suits {player}. That was a performance with style and consequence.']],
        ],
        'brazil' => [
            'positive' => [['family' => 'futebol_flair', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'JOGO_BONITO', 'text' => 'That first touch had futebol in it. {player} brought the joy back to the move.'], ['family' => 'brazilian_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'JOGO_BONITO', 'text' => 'Meu amigo, what a finish from {player}. Pure disrespect for the angle.']],
            'negative' => [['family' => 'brazilian_frustration', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'FUTEBOL', 'text' => 'The talent was there, but the team lost the rhythm and the result with it.']],
            'major' => [['family' => 'jogo_bonito_moment', 'opening' => 'statement', 'slang' => '', 'emoji' => '❤️', 'native' => false, 'reference' => 'JOGO_BONITO', 'text' => 'That is why people fall in love with futebol. {player} made a moment out of nothing.']],
        ],
        'argentina' => [
            'positive' => [['family' => 'hincha_passion', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'HINCHA_PASSION', 'text' => 'That is a moment for every hincha. {player} played it with courage.'], ['family' => 'argentine_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'HINCHA_PASSION', 'text' => 'Hermano, that finish. Fútbol at its most alive.']],
            'negative' => [['family' => 'argentine_frustration', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'HINCHA_PASSION', 'text' => 'The heart was there, but the match needed more calm in the decisive moments.']],
            'major' => [['family' => 'pasion_big_night', 'opening' => 'statement', 'slang' => '', 'emoji' => '❤️', 'native' => false, 'reference' => 'HINCHA_PASSION', 'text' => 'You remember nights like this. {player} gave the hinchas something real.']],
        ],
        'philippines' => [
            'positive' => [['family' => 'pinoy_overseas_pride', 'opening' => 'supporter', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'PINOY_PRIDE', 'text' => 'Our boy is making the whole football world watch. What a shift, {player} 😭'], ['family' => 'pinoy_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'PINOY_PRIDE', 'text' => 'Grabe, {player} really did that in Europe. Our boy is different today.']],
            'negative' => [['family' => 'pinoy_support', 'opening' => 'supporter', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'PINOY_PRIDE', 'text' => 'Hard result, but we know our boy can answer in the next match.']],
            'major' => [['family' => 'pinoy_landmark', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'PINOY_PRIDE', 'text' => 'A huge day for {player} and for everyone back home watching.']],
        ],
        'nigeria' => [
            'positive' => [['family' => 'nigerian_banter', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'FOOTBALL_BANTER', 'text' => 'He saw the chance and finished it properly. {player} is giving us a serious performance.'], ['family' => 'nigerian_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'FOOTBALL_BANTER', 'text' => 'Abeg, who is stopping {player} tonight? That finish was serious.']],
            'negative' => [['family' => 'nigerian_frustration', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'FOOTBALL_BANTER', 'text' => 'The effort was there, but the team gave away too much in the important moments.']],
            'major' => [['family' => 'nigerian_landmark', 'opening' => 'statement', 'slang' => 'cold', 'emoji' => '😭', 'native' => false, 'reference' => 'FOOTBALL_BANTER', 'text' => '{player} was cold when the pressure arrived. Big moment, big answer.']],
        ],
        'japan' => [
            'positive' => [['family' => 'japanese_composure', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'COMPOSED_FOOTBALL', 'text' => 'The detail was excellent: clean movement, patience, and a precise finish from {player}.'], ['family' => 'japanese_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'COMPOSED_FOOTBALL', 'text' => 'Sugoi finish. {player} stayed calm when the whole match sped up.']],
            'negative' => [['family' => 'japanese_reflection', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'COMPOSED_FOOTBALL', 'text' => 'The next step is clear: keep the structure, make the final decision earlier.']],
            'major' => [['family' => 'japanese_landmark', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'COMPOSED_FOOTBALL', 'text' => 'A meaningful football moment. {player} earned it through discipline and timing.']],
        ],
        'mexico' => [
            'positive' => [['family' => 'mexican_cancha', 'opening' => 'statement', 'slang' => '', 'emoji' => '😭', 'native' => false, 'reference' => 'CANCHA', 'text' => 'That was pure cancha instinct from {player}; the finish arrived before the defence could think.'], ['family' => 'mexican_joy', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'reference' => 'CANCHA', 'text' => 'Qué golazo. {player} found the one gap that mattered.']],
            'negative' => [['family' => 'mexican_frustration', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'reference' => 'CANCHA', 'text' => 'The team had the match in reach and let it slip. That is the frustrating part.']],
            'major' => [['family' => 'mexican_landmark', 'opening' => 'statement', 'slang' => '', 'emoji' => '❤️', 'native' => false, 'reference' => 'CANCHA', 'text' => 'A big football memory from {player}; everyone in the cancha would appreciate that.']],
        ],
    ];

    /** @var array<string, string> */
    private const CULTURE_ALIASES = [
        'england' => 'england', 'spain' => 'spain', 'germany' => 'germany', 'italy' => 'italy', 'france' => 'france',
        'brazil' => 'brazil', 'argentina' => 'argentina', 'philippines' => 'philippines', 'nigeria' => 'nigeria', 'japan' => 'japan', 'mexico' => 'mexico',
        'united-states' => 'global', 'usa' => 'global', 'south-korea' => 'japan', 'portugal' => 'spain', 'netherlands' => 'germany', 'belgium' => 'france', 'croatia' => 'italy', 'ghana' => 'nigeria', 'morocco' => 'france',
    ];

    /** @var array<string, array<string, array{family:string,opening:string,slang:string,emoji:string,native:bool,text:string}>> */
    private const CULTURE_THREAD_REPLIES = [
        'england' => ['agree' => ['family' => 'english_fair_play', 'opening' => 'fair_play', 'slang' => 'proper', 'emoji' => '', 'native' => false, 'text' => 'Fair play, the lad earned that one.'], 'disagree' => ['family' => 'english_calm_down', 'opening' => 'question', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'One good game and we are rewriting the whole season?'], 'rival_banter' => ['family' => 'english_away_banter', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'Enjoy it; we will see how the next away day goes.']],
        'spain' => ['agree' => ['family' => 'spanish_lectura', 'opening' => 'interjection', 'slang' => '', 'emoji' => '', 'native' => true, 'text' => 'Qué lectura; the finish deserved that praise.'], 'disagree' => ['family' => 'spanish_calma', 'opening' => 'calm', 'slang' => '', 'emoji' => '', 'native' => true, 'text' => 'Calma, one match does not settle the argument.'], 'rival_banter' => ['family' => 'spanish_long_season', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'Enjoy the moment, but the league is long.']],
        'germany' => ['agree' => ['family' => 'german_structure', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'That is a fair assessment; the structure was excellent.'], 'disagree' => ['family' => 'german_measure', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'The result is one thing; the full performance is another.'], 'rival_banter' => ['family' => 'german_next_match', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'One match is not a season.']],
        'italy' => ['agree' => ['family' => 'italian_giusto', 'opening' => 'interjection', 'slang' => '', 'emoji' => '', 'native' => true, 'text' => 'Giusto, the movement made the difference.'], 'disagree' => ['family' => 'italian_tactics', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'Tactics still matter more than the headline.'], 'rival_banter' => ['family' => 'italian_round', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'We will discuss it again after the next round.']],
        'france' => ['agree' => ['family' => 'french_oui', 'opening' => 'interjection', 'slang' => '', 'emoji' => '', 'native' => true, 'text' => 'Oui, the quality was obvious there.'], 'disagree' => ['family' => 'french_crown', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'Let us not crown the whole season from one night.'], 'rival_banter' => ['family' => 'french_next', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'The next match will give us more to discuss.']],
        'brazil' => ['agree' => ['family' => 'brazilian_touch', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'text' => 'Meu amigo, you cannot argue with that touch.'], 'disagree' => ['family' => 'brazilian_calma', 'opening' => 'calm', 'slang' => '', 'emoji' => '', 'native' => true, 'text' => 'Calma; one moment does not decide the whole story.'], 'rival_banter' => ['family' => 'brazilian_joy', 'opening' => 'banter', 'slang' => '', 'emoji' => '😭', 'native' => false, 'text' => 'Let him enjoy the night; the ball was beautiful.']],
        'argentina' => ['agree' => ['family' => 'argentine_hermano', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'text' => 'Hermano, that was a proper football moment.'], 'disagree' => ['family' => 'argentine_balance', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'The passion is fine, but the match still needs balance.'], 'rival_banter' => ['family' => 'argentine_hincha', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'Let the hinchas enjoy this one.']],
        'philippines' => ['agree' => ['family' => 'pinoy_evidence', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'text' => 'Grabe, our boy gave you evidence tonight.'], 'disagree' => ['family' => 'pinoy_patience', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'One game first; we can keep the bigger claims for later.'], 'rival_banter' => ['family' => 'pinoy_moment', 'opening' => 'banter', 'slang' => '', 'emoji' => '😭', 'native' => false, 'text' => 'Let our boy have this moment 😭']],
        'nigeria' => ['agree' => ['family' => 'nigerian_evidence', 'opening' => 'interjection', 'slang' => '', 'emoji' => '', 'native' => true, 'text' => 'Abeg, the evidence is right there.'], 'disagree' => ['family' => 'nigerian_measure', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'No need to overdo it; football will test him again.'], 'rival_banter' => ['family' => 'nigerian_moment', 'opening' => 'banter', 'slang' => '', 'emoji' => '😭', 'native' => false, 'text' => 'Let him enjoy the big moment.']],
        'japan' => ['agree' => ['family' => 'japanese_precision', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'That was a precise reading of the game.'], 'disagree' => ['family' => 'japanese_detail', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'The detail matters more than one headline.'], 'rival_banter' => ['family' => 'japanese_measure', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'We can appreciate the moment and stay measured.']],
        'mexico' => ['agree' => ['family' => 'mexican_cancha', 'opening' => 'interjection', 'slang' => '', 'emoji' => '😭', 'native' => true, 'text' => 'Qué momento; the finish earned the noise.'], 'disagree' => ['family' => 'mexican_long_story', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'The match was good, but the whole story is longer.'], 'rival_banter' => ['family' => 'mexican_move', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'native' => false, 'text' => 'Enjoy the cancha moment, then we move.']],
    ];

    /** @var array<string, list<string>> */
    private const TEMPLATES = [
        'fan' => [
            'match_goal' => ['That finish from {player} changed the {competition} night. {score}.', 'You could feel that goal coming. {player} delivered for {team}.', '{player} gave us a moment to remember. What a goal for {team}.'],
            'match_assist' => ['That pass from {player} opened the whole match. {team} deserved that moment.', '{player} saw the opening before anyone else. A huge assist in the {competition}.'],
            'match_decisive_goal' => ['Decisive when it mattered: {player} put {team} through. {score}.', 'Big players own big moments. {player} changed this knockout night.'],
            'match_major_contribution' => ['{player} shaped the result for {team}. That was a serious performance.', 'A result built on work like that from {player}.'],
            'match_strong_performance' => ['{player} was everywhere for {team} today. A performance worth noticing.', 'That was a proper {player} performance: composed, committed, effective.'],
            'match_result' => ['A big {competition} result for {team}: {score}.', '{team} got the job done tonight. {player} played their part.'],
            'match_red_card' => ['The red changed the night for {team}. {player} will have to answer for the moment.', 'A difficult night after {player} was dismissed. The team will regroup.'],
            'transfer_request' => ['Transfer talk is now public for {player}. Every next step matters.', 'A new chapter may be coming for {player}.'],
            'transfer' => ['Welcome to {team}, {player}. A fresh football chapter starts now.', '{player} has chosen a new challenge with {team}.'],
            'free_agent_signing' => ['A new home found: welcome to {team}, {player}.', '{player} keeps the Career moving with {team}.'],
            'award' => ['That recognition is deserved. {player} has earned {headline}.', 'A special individual honour for {player}: {headline}.'],
            'honour' => ['History made: {player} can call {headline} part of the Career now.', 'The medals matter. Congratulations to {player} on {headline}.'],
            'record' => ['Another page in the record book for {player}: {headline}.', '{player} keeps raising the standard with {headline}.'],
            'milestone' => ['A Career milestone for {player}: {headline}.', 'Small steps become a legacy. Well done, {player}.'],
            'retirement' => ['A full playing Career deserves respect. Thank you for the memories, {player}.', '{player} has closed a playing Career built one Season at a time.'],
            'injury' => ['Tough news for {player}. The supporters are behind the recovery.'],
            'return' => ['Good to see {player} back in the football conversation. Welcome back.'],
            'career_choice' => ['A football decision has shaped {player}\'s next chapter.', 'The Career keeps moving, one meaningful choice at a time.'],
        ],
        'media' => [
            'match_goal' => ['Match report reaction: {player}\'s goal changed {team}\'s {competition} result ({score}).', 'The talking point from {competition}: {player} delivered in front of goal.'],
            'match_assist' => ['A measured assist from {player} became a key part of {team}\'s {score} result.', '{player}\'s creative contribution deserves attention after the {competition}.'],
            'match_decisive_goal' => ['Decisive contribution: {player} sent {team} through with the moment that mattered.', 'The {competition} pressure produced a defining moment from {player}.'],
            'match_major_contribution' => ['{player} had a material influence on {team}\'s {score} result.', 'A performance with consequence from {player}; the context made it significant.'],
            'match_strong_performance' => ['A strong rating and real evidence put {player} among the day\'s stories.', '{player}\'s work without the ball and on it stood out for {team}.'],
            'match_result' => ['{team} leave the {competition} with a {score} result and a useful contribution from {player}.', 'A significant result for {team}; {player} was involved in the story.'],
            'match_red_card' => ['Discipline became the defining detail after {player}\'s dismissal.', '{player}\'s red card changed the match narrative for {team}.'],
            'transfer_request' => ['Transfer request confirmed: {player}\'s Club future is now a live Career question.', 'The market conversation around {player} has moved from private to public.'],
            'transfer' => ['Confirmed: {player} begins a new Club chapter with {team}.', 'A meaningful Career move sees {player} join {team}.'],
            'free_agent_signing' => ['Free-agent signing confirmed: {player} has joined {team}.', '{player} has found the next Club platform after free agency.'],
            'award' => ['Individual recognition for {player}: {headline}.', 'The Season evidence has produced a major award for {player}: {headline}.'],
            'honour' => ['A landmark achievement enters {player}\'s record: {headline}.', '{player}\'s Career now includes {headline}.'],
            'record' => ['Record watch: {player} has reached {headline}.', 'The numbers have made {headline} part of {player}\'s story.'],
            'milestone' => ['Milestone reached by {player}: {headline}.', 'A durable Career marker for {player}: {headline}.'],
            'retirement' => ['Playing Career complete: {player} retires with a record of football worth remembering.', 'The final chapter is complete for {player}; the evidence now belongs to Career history.'],
            'injury' => ['Availability update: {player} begins a recovery period after the recorded injury.'],
            'return' => ['Availability update: {player} has returned from the recorded injury.'],
            'career_choice' => ['A controlled Career decision has changed the context around {player}.'],
        ],
        'club' => [
            'match_goal' => ['A goal, a result, and a proud night for {team}. Well done, {player}.', '{team} celebrates the contribution that helped secure {score}.'],
            'match_assist' => ['The team effort showed again: {player} supplied the assist in {team}\'s {score}.', 'A selfless moment from {player} helped {team} move forward.'],
            'match_decisive_goal' => ['A defining {competition} moment for {team}. {player}, take a bow.', '{team} advance because {player} stood up when it mattered.'],
            'match_major_contribution' => ['The badge was represented well today. {player} made a difference for {team}.'],
            'match_strong_performance' => ['Professional, committed, and effective: a strong {team} display from {player}.'],
            'match_result' => ['Full-time: {team} {score} in the {competition}. Every contribution counted.'],
            'match_red_card' => ['We will review the moment and respond together. The team remains united around {player}.'],
            'transfer' => ['{team} is delighted to welcome {player} to the squad.'],
            'free_agent_signing' => ['{team} welcomes new signing {player} to the next chapter.'],
            'award' => ['Congratulations to {player} on {headline}. A proud day for {team}.'],
            'honour' => ['Our history grows with {player}: {headline}.'],
            'record' => ['Another Club-era marker for {player}: {headline}.'],
            'milestone' => ['A milestone worth celebrating for {player}: {headline}.'],
            'retirement' => ['The Club thanks {player} for a completed playing Career and the memories created here.'],
            'injury' => ['The Club is supporting {player} through the recorded injury.'],
            'return' => ['The Club welcomes {player} back after the recorded injury.'],
        ],
        'competition' => [
            'match_goal' => ['{player} added a decisive attacking moment to the {competition}.', 'The {competition} spotlight found {player} tonight.'],
            'match_decisive_goal' => ['{player} owns a defining {competition} moment as {team} advance.'],
            'match_major_contribution' => ['A significant {competition} performance from {player}.'],
            'match_strong_performance' => ['The {competition} record notes a strong showing from {player}.'],
            'match_result' => ['{team} record a {score} result in the {competition}.'],
            'award' => ['The {competition} recognises {player}: {headline}.'],
            'honour' => ['A new {competition} chapter for {player}: {headline}.'],
            'record' => ['The {competition} record now includes {headline} for {player}.'],
        ],
        'national' => [
            'match_goal' => ['A national-team moment for {player}: {score} in the {competition}.'],
            'match_assist' => ['{player} created a valuable moment for the national team in the {competition}.'],
            'match_major_contribution' => ['The national-team story includes a major contribution from {player}.'],
            'match_strong_performance' => ['A composed international performance from {player}.'],
            'match_result' => ['The national team finish the {competition} match {score}.'],
            'award' => ['International football celebrates {player}: {headline}.'],
            'honour' => ['A national achievement for {player}: {headline}.'],
            'milestone' => ['A new international milestone for {player}: {headline}.'],
            'injury' => ['The national-team picture will wait for {player} to recover.'],
            'return' => ['{player} is back in the international conversation after recovery.'],
        ],
        'teammate' => [
            'match_goal' => ['That is the {player} we see every day. Brilliant finish.'],
            'match_assist' => ['You made that chance, {player}. Great vision.'],
            'match_decisive_goal' => ['Big moment, {player}. We are through together.'],
            'match_major_contribution' => ['That was a proper team performance from {player}.'],
            'award' => ['Fully deserved, {player}. We know the work behind it.'],
            'honour' => ['What a memory for the group, {player}.'],
            'milestone' => ['Congratulations, {player}. More to come.'],
        ],
        'rival' => [
            'match_goal' => ['Good finish, {player}. We will remember the next duel.'],
            'match_decisive_goal' => ['You got the decisive moment today, {player}. The rivalry continues.'],
            'match_major_contribution' => ['Credit where it is due: {player} made the difference.'],
            'match_red_card' => ['A difficult moment for {player}; football will answer on the pitch.'],
        ],
        'player' => [
            'victory' => ['Proud of the team tonight. Thank you for the support.'],
            'defeat' => ['We take responsibility, learn, and keep working together.'],
            'transfer' => ['Thank you to the Club and supporters. I am ready for the next chapter.'],
            'red_card' => ['I accept responsibility for the dismissal. I will respond on the pitch.'],
            'achievement' => ['Honoured by this recognition. This belongs to the team and everyone who helped me.'],
        ],
    ];

    /** @var array<string, list<string>> */
    private const VOICES_BY_ACTOR = [
        'fan' => ['supportive_fan', 'reactionary_fan', 'casual_fan', 'meme_account', 'optimistic_fan', 'pessimistic_fan'],
        'media' => ['neutral_viewer', 'tactical_fan', 'old_school_fan'],
        'club' => ['supportive_fan', 'old_school_fan'],
        'competition' => ['neutral_viewer', 'tactical_fan'],
        'national' => ['supportive_fan', 'neutral_viewer'],
        'teammate' => ['supportive_fan', 'casual_fan'],
        'rival' => ['rival_fan', 'reactionary_fan', 'meme_account'],
    ];

    /**
     * Presentation-only reactions. Echo remains the source of event kinds;
     * these entries only vary the public voice around canonical context.
     *
     * @var array<string, list<array{voice:string,family:string,opening:string,slang:string,emoji:string,actors:list<string>,results:list<string>,text:string}>>
     */
    private const REACTION_CATALOG = [
        'match_goal' => [
            ['voice' => 'supportive_fan', 'family' => 'own_celebration', 'opening' => 'caps', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club'], 'results' => ['win', 'draw'], 'text' => "THAT'S MY {player} 😭"],
            ['voice' => 'reactionary_fan', 'family' => 'agenda', 'opening' => 'statement', 'slang' => 'agenda', 'emoji' => '', 'actors' => ['fan'], 'results' => ['win', 'draw', 'loss'], 'text' => '{player} scores once and the agenda is back on.'],
            ['voice' => 'meme_account', 'family' => 'meme_callback', 'opening' => 'lowercase', 'slang' => 'bro', 'emoji' => '💀', 'actors' => ['fan', 'rival'], 'results' => ['win', 'draw', 'loss'], 'text' => "bro remembered he's a footballer 💀"],
            ['voice' => 'pessimistic_fan', 'family' => 'loss_acknowledgement', 'opening' => 'fragment', 'slang' => '', 'emoji' => '', 'actors' => ['fan'], 'results' => ['loss'], 'text' => 'Good goal. Shame about the scoreline.'],
            ['voice' => 'casual_fan', 'family' => 'mock_disbelief', 'opening' => 'question', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan'], 'results' => ['win', 'draw', 'loss'], 'text' => 'How has {player} even scored from there 😭'],
            ['voice' => 'rival_fan', 'family' => 'reluctant_praise', 'opening' => 'respect', 'slang' => '', 'emoji' => '', 'actors' => ['rival'], 'results' => ['win', 'draw', 'loss'], 'text' => 'Respect. Hate the badge but that was class.'],
            ['voice' => 'neutral_viewer', 'family' => 'plain_evidence', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win', 'draw', 'loss'], 'text' => 'The finish was excellent; {team} still leave with a {score} result.'],
            ['voice' => 'tactical_fan', 'family' => 'movement_detail', 'opening' => 'analysis', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win', 'draw', 'loss'], 'text' => 'The movement before the finish created the opening.'],
        ],
        'match_assist' => [
            ['voice' => 'supportive_fan', 'family' => 'creator_praise', 'opening' => 'supporter', 'slang' => '', 'emoji' => '❤️', 'actors' => ['fan', 'club'], 'results' => ['win', 'draw'], 'text' => "That's my creator ❤️"],
            ['voice' => 'casual_fan', 'family' => 'vision_reaction', 'opening' => 'lowercase', 'slang' => 'cold', 'emoji' => '', 'actors' => ['fan'], 'results' => ['win', 'draw', 'loss'], 'text' => '{player} saw that pass before everyone else. Cold.'],
            ['voice' => 'pessimistic_fan', 'family' => 'loss_acknowledgement', 'opening' => 'fragment', 'slang' => '', 'emoji' => '', 'actors' => ['fan'], 'results' => ['loss'], 'text' => 'Lovely assist. Awful result.'],
            ['voice' => 'neutral_viewer', 'family' => 'chance_creation', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win', 'draw', 'loss'], 'text' => '{player} created the chance that changed the sequence.'],
            ['voice' => 'tactical_fan', 'family' => 'final_pass', 'opening' => 'analysis', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => ['win', 'draw', 'loss'], 'text' => 'The final pass from {player} broke the defensive line.'],
            ['voice' => 'meme_account', 'family' => 'sneaky_creator', 'opening' => 'fragment', 'slang' => 'lowkey', 'emoji' => '😭', 'actors' => ['fan'], 'results' => ['win', 'draw', 'loss'], 'text' => 'lowkey that assist was filthy 😭'],
        ],
        'match_decisive_goal' => [
            ['voice' => 'supportive_fan', 'family' => 'big_moment', 'opening' => 'caps', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club'], 'results' => ['win'], 'text' => "HE'S DONE IT. {player} 😭"],
            ['voice' => 'meme_account', 'family' => 'different_gravy', 'opening' => 'lowercase', 'slang' => 'different gravy', 'emoji' => '💀', 'actors' => ['fan'], 'results' => ['win'], 'text' => "nah {player} is different gravy 💀"],
            ['voice' => 'rival_fan', 'family' => 'reluctant_praise', 'opening' => 'respect', 'slang' => '', 'emoji' => '', 'actors' => ['rival'], 'results' => ['win'], 'text' => 'Fine. That was the moment, and it was deserved.'],
            ['voice' => 'neutral_viewer', 'family' => 'decisive_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win'], 'text' => '{player} supplied the decisive moment as {team} advanced.'],
            ['voice' => 'old_school_fan', 'family' => 'big_game_memory', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'actors' => ['club'], 'results' => ['win'], 'text' => 'That is what big-game players are remembered for.'],
        ],
        'match_major_contribution' => [
            ['voice' => 'supportive_fan', 'family' => 'performance_pride', 'opening' => 'supporter', 'slang' => '', 'emoji' => '❤️', 'actors' => ['fan', 'club'], 'results' => ['win', 'draw'], 'text' => "That's my player. Proper shift ❤️"],
            ['voice' => 'reactionary_fan', 'family' => 'agenda_reversal', 'opening' => 'statement', 'slang' => 'cooked', 'emoji' => '', 'actors' => ['fan'], 'results' => ['win', 'draw'], 'text' => '{player} cooked today and I will be hearing no revisionism.'],
            ['voice' => 'pessimistic_fan', 'family' => 'individual_in_loss', 'opening' => 'fragment', 'slang' => '', 'emoji' => '', 'actors' => ['fan'], 'results' => ['loss'], 'text' => 'Good individual display. We still lost.'],
            ['voice' => 'tactical_fan', 'family' => 'complete_display', 'opening' => 'analysis', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win', 'draw', 'loss'], 'text' => 'The useful detail was the work both in and out of possession.'],
            ['voice' => 'rival_fan', 'family' => 'rival_credit', 'opening' => 'respect', 'slang' => '', 'emoji' => '', 'actors' => ['rival'], 'results' => ['win', 'draw', 'loss'], 'text' => 'Hate admitting it, but {player} made the difference.'],
        ],
        'match_strong_performance' => [
            ['voice' => 'supportive_fan', 'family' => 'form_pride', 'opening' => 'supporter', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club'], 'results' => ['win', 'draw'], 'text' => "That's my {player}. What a shift 😭"],
            ['voice' => 'reactionary_fan', 'family' => 'form_agenda', 'opening' => 'statement', 'slang' => 'locked in', 'emoji' => '', 'actors' => ['fan'], 'results' => ['win', 'draw'], 'text' => '{player} was locked in today. More of this, please.'],
            ['voice' => 'optimistic_fan', 'family' => 'form_turn', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'actors' => ['fan'], 'results' => ['win', 'draw'], 'text' => 'This is the performance we can build on.'],
            ['voice' => 'pessimistic_fan', 'family' => 'good_in_bad_result', 'opening' => 'fragment', 'slang' => '', 'emoji' => '', 'actors' => ['fan'], 'results' => ['loss'], 'text' => 'One of the few positives in that result.'],
            ['voice' => 'neutral_viewer', 'family' => 'rating_evidence', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win', 'draw', 'loss'], 'text' => '{player} gave the match a clear performance to remember.'],
            ['voice' => 'old_school_fan', 'family' => 'professional_shift', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'actors' => ['club'], 'results' => ['win', 'draw'], 'text' => 'Professional work from first whistle to last.'],
        ],
        'match_result' => [
            ['voice' => 'supportive_fan', 'family' => 'result_joy', 'opening' => 'caps', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club'], 'results' => ['win'], 'text' => "WE'LL TAKE THAT. {score} 😭"],
            ['voice' => 'pessimistic_fan', 'family' => 'result_frustration', 'opening' => 'fragment', 'slang' => '', 'emoji' => '', 'actors' => ['fan', 'club'], 'results' => ['loss'], 'text' => 'Not good enough. {score}.'],
            ['voice' => 'casual_fan', 'family' => 'result_disbelief', 'opening' => 'question', 'slang' => 'what', 'emoji' => '💀', 'actors' => ['fan'], 'results' => ['loss'], 'text' => 'What am I watching? {score} 💀'],
            ['voice' => 'neutral_viewer', 'family' => 'result_record', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win', 'draw', 'loss'], 'text' => '{team} leave the {competition} with a {score} result.'],
            ['voice' => 'tactical_fan', 'family' => 'result_context', 'opening' => 'analysis', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => ['win', 'draw', 'loss'], 'text' => 'The scoreline reflects a match decided by the margins.'],
        ],
        'match_red_card' => [
            ['voice' => 'pessimistic_fan', 'family' => 'discipline_frustration', 'opening' => 'fragment', 'slang' => '', 'emoji' => '💀', 'actors' => ['fan', 'club'], 'results' => ['win', 'draw', 'loss'], 'text' => 'That red changed everything 💀'],
            ['voice' => 'reactionary_fan', 'family' => 'discipline_criticism', 'opening' => 'question', 'slang' => 'cooked', 'emoji' => '', 'actors' => ['fan'], 'results' => ['win', 'draw', 'loss'], 'text' => 'Why are we making it this hard? {player} has cooked the whole night.'],
            ['voice' => 'neutral_viewer', 'family' => 'discipline_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => ['win', 'draw', 'loss'], 'text' => 'The dismissal became the defining detail of the match.'],
            ['voice' => 'old_school_fan', 'family' => 'discipline_lesson', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'actors' => ['club'], 'results' => ['win', 'draw', 'loss'], 'text' => 'The team cannot give away moments like that.'],
        ],
        'transfer' => [
            ['voice' => 'supportive_fan', 'family' => 'new_chapter_welcome', 'opening' => 'supporter', 'slang' => '', 'emoji' => '❤️', 'actors' => ['club'], 'results' => [], 'text' => "Welcome to {team}, {player}. Let's get to work ❤️"],
            ['voice' => 'reactionary_fan', 'family' => 'market_verdict', 'opening' => 'statement', 'slang' => 'aura', 'emoji' => '', 'actors' => ['fan'], 'results' => [], 'text' => '{player} arrives with serious aura. Now prove it.'],
            ['voice' => 'neutral_viewer', 'family' => 'market_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => [], 'text' => '{player} begins a new Club chapter with {team}.'],
            ['voice' => 'old_school_fan', 'family' => 'market_welcome', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'actors' => ['club'], 'results' => [], 'text' => 'A new shirt, the same work. Welcome, {player}.'],
        ],
        'free_agent_signing' => [
            ['voice' => 'supportive_fan', 'family' => 'free_agent_welcome', 'opening' => 'supporter', 'slang' => '', 'emoji' => '😭', 'actors' => ['club'], 'results' => [], 'text' => 'A new home found. Welcome, {player} 😭'],
            ['voice' => 'meme_account', 'family' => 'free_agent_meme', 'opening' => 'lowercase', 'slang' => 'streets', 'emoji' => '💀', 'actors' => ['fan'], 'results' => [], 'text' => 'the streets said sign him and they were right 💀'],
            ['voice' => 'neutral_viewer', 'family' => 'free_agent_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => [], 'text' => 'Free agency ends with {player} joining {team}.'],
        ],
        'transfer_request' => [
            ['voice' => 'reactionary_fan', 'family' => 'market_drama', 'opening' => 'question', 'slang' => 'ngl', 'emoji' => '', 'actors' => ['fan'], 'results' => [], 'text' => 'ngl the market just got interesting.'],
            ['voice' => 'neutral_viewer', 'family' => 'market_update', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => [], 'text' => 'The transfer request makes {player}\'s next step a live question.'],
        ],
        'award' => [
            ['voice' => 'supportive_fan', 'family' => 'award_pride', 'opening' => 'caps', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club'], 'results' => [], 'text' => "THAT'S MY PLAYER. {headline} 😭"],
            ['voice' => 'reactionary_fan', 'family' => 'award_receipt', 'opening' => 'statement', 'slang' => 'prop', 'emoji' => '', 'actors' => ['fan'], 'results' => [], 'text' => 'The award is the receipt. Give {player} their prop.'],
            ['voice' => 'neutral_viewer', 'family' => 'award_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'national'], 'results' => [], 'text' => '{headline} is now part of {player}\'s documented Season.'],
            ['voice' => 'old_school_fan', 'family' => 'award_merit', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'actors' => ['club'], 'results' => [], 'text' => 'Recognition follows the work. Well deserved, {player}.'],
        ],
        'honour' => [
            ['voice' => 'supportive_fan', 'family' => 'honour_pride', 'opening' => 'supporter', 'slang' => '', 'emoji' => '❤️', 'actors' => ['fan', 'club'], 'results' => [], 'text' => 'History made by {player} ❤️'],
            ['voice' => 'meme_account', 'family' => 'honour_aura', 'opening' => 'lowercase', 'slang' => 'generational', 'emoji' => '😭', 'actors' => ['fan'], 'results' => [], 'text' => 'generational stuff from {player} 😭'],
            ['voice' => 'neutral_viewer', 'family' => 'honour_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'national'], 'results' => [], 'text' => '{headline} joins the durable record of {player}.'],
        ],
        'record' => [
            ['voice' => 'supportive_fan', 'family' => 'record_pride', 'opening' => 'caps', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club'], 'results' => [], 'text' => 'Another record for {player}. Unreal 😭'],
            ['voice' => 'tactical_fan', 'family' => 'record_evidence', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'results' => [], 'text' => 'The record is the clearest evidence of {player}\'s Season.'],
            ['voice' => 'reactionary_fan', 'family' => 'record_agenda', 'opening' => 'statement', 'slang' => 'cold', 'emoji' => '', 'actors' => ['fan'], 'results' => [], 'text' => '{player} has been cold all Season. The numbers agree.'],
        ],
        'milestone' => [
            ['voice' => 'supportive_fan', 'family' => 'milestone_pride', 'opening' => 'supporter', 'slang' => '', 'emoji' => '❤️', 'actors' => ['fan', 'club', 'national'], 'results' => [], 'text' => 'Small steps, big Career. Well done, {player} ❤️'],
            ['voice' => 'casual_fan', 'family' => 'milestone_callback', 'opening' => 'lowercase', 'slang' => 'lowkey', 'emoji' => '', 'actors' => ['fan'], 'results' => [], 'text' => 'lowkey this is a big one for {player}.'],
            ['voice' => 'neutral_viewer', 'family' => 'milestone_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'national'], 'results' => [], 'text' => '{headline} becomes a new marker in {player}\'s Career.'],
        ],
        'retirement' => [
            ['voice' => 'supportive_fan', 'family' => 'retirement_thanks', 'opening' => 'supporter', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club'], 'results' => [], 'text' => 'Thank you for the memories, {player} 😭'],
            ['voice' => 'neutral_viewer', 'family' => 'retirement_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => [], 'text' => 'The playing Career is complete for {player}; the record now belongs to history.'],
            ['voice' => 'old_school_fan', 'family' => 'retirement_respect', 'opening' => 'statement', 'slang' => '', 'emoji' => '', 'actors' => ['fan'], 'results' => [], 'text' => 'A full playing Career deserves respect.'],
        ],
        'injury' => [
            ['voice' => 'supportive_fan', 'family' => 'injury_support', 'opening' => 'supporter', 'slang' => '', 'emoji' => '❤️', 'actors' => ['fan', 'club'], 'results' => [], 'text' => 'Wishing {player} a steady recovery ❤️'],
            ['voice' => 'neutral_viewer', 'family' => 'injury_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => [], 'text' => 'The recorded injury takes {player} out of the immediate picture.'],
        ],
        'return' => [
            ['voice' => 'supportive_fan', 'family' => 'comeback_joy', 'opening' => 'caps', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club', 'national'], 'results' => [], 'text' => "HE'S BACK 😭"],
            ['voice' => 'meme_account', 'family' => 'comeback_meme', 'opening' => 'lowercase', 'slang' => 'aura', 'emoji' => '💀', 'actors' => ['fan'], 'results' => [], 'text' => 'the aura has returned 💀'],
            ['voice' => 'neutral_viewer', 'family' => 'comeback_fact', 'opening' => 'plain', 'slang' => '', 'emoji' => '', 'actors' => ['media'], 'results' => [], 'text' => '{player} is available again after the recorded injury.'],
        ],
        'career_choice' => [
            ['voice' => 'supportive_fan', 'family' => 'career_support', 'opening' => 'supporter', 'slang' => '', 'emoji' => '❤️', 'actors' => ['teammate'], 'results' => [], 'text' => 'Whatever comes next, we are with you, {player} ❤️'],
            ['voice' => 'casual_fan', 'family' => 'career_callback', 'opening' => 'lowercase', 'slang' => 'ngl', 'emoji' => '', 'actors' => ['teammate'], 'results' => [], 'text' => 'ngl this next chapter is going to be interesting.'],
        ],
    ];

    /** @var array<string, list<array{voice:string,family:string,opening:string,slang:string,emoji:string,actors:list<string>,text:string}>> */
    private const THREAD_CATALOG = [
        'agree' => [
            ['voice' => 'supportive_fan', 'family' => 'agree_support', 'opening' => 'exactly', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'club', 'teammate'], 'text' => 'Exactly. Let him have this one 😭'],
            ['voice' => 'casual_fan', 'family' => 'agree_casual', 'opening' => 'fair', 'slang' => 'fair', 'emoji' => '', 'actors' => ['fan', 'teammate'], 'text' => 'Fair tbh, cannot even argue with that.'],
            ['voice' => 'neutral_viewer', 'family' => 'agree_measured', 'opening' => 'measured', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'text' => 'That is a fair reading of the moment.'],
        ],
        'disagree' => [
            ['voice' => 'reactionary_fan', 'family' => 'pushback', 'opening' => 'challenge', 'slang' => 'nah', 'emoji' => '', 'actors' => ['fan', 'rival'], 'text' => 'Nah. One moment does not rewrite the whole match.'],
            ['voice' => 'tactical_fan', 'family' => 'tactical_pushback', 'opening' => 'analysis', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition'], 'text' => 'The result needs a little more context than that.'],
            ['voice' => 'pessimistic_fan', 'family' => 'skeptic_pushback', 'opening' => 'skeptic', 'slang' => '', 'emoji' => '💀', 'actors' => ['fan'], 'text' => 'Be serious, we are doing this after one good moment? 💀'],
        ],
        'reluctant_agreement' => [
            ['voice' => 'rival_fan', 'family' => 'reluctant_credit', 'opening' => 'reluctant', 'slang' => '', 'emoji' => '', 'actors' => ['rival'], 'text' => 'Fair enough. Hate agreeing, but that was class.'],
            ['voice' => 'old_school_fan', 'family' => 'reluctant_respect', 'opening' => 'respect', 'slang' => '', 'emoji' => '', 'actors' => ['club', 'media'], 'text' => 'Credit where it is due. That was a proper moment.'],
        ],
        'defend_player' => [
            ['voice' => 'supportive_fan', 'family' => 'defend_player', 'opening' => 'defend', 'slang' => '', 'emoji' => '❤️', 'actors' => ['fan', 'teammate', 'club'], 'text' => 'Let him have this one. The evidence is right there ❤️'],
            ['voice' => 'optimistic_fan', 'family' => 'defend_optimism', 'opening' => 'optimism', 'slang' => '', 'emoji' => '', 'actors' => ['fan'], 'text' => 'There is something to build on here.'],
        ],
        'rival_banter' => [
            ['voice' => 'rival_fan', 'family' => 'rival_banter', 'opening' => 'banter', 'slang' => 'calm down', 'emoji' => '', 'actors' => ['rival', 'fan'], 'text' => "It's September, mate. Calm down."],
            ['voice' => 'meme_account', 'family' => 'rival_meme', 'opening' => 'lowercase', 'slang' => 'aura', 'emoji' => '💀', 'actors' => ['rival', 'fan'], 'text' => 'bookmarking this take for later 💀'],
            ['voice' => 'neutral_viewer', 'family' => 'measured_banter', 'opening' => 'banter', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition', 'club'], 'text' => 'One result at a time; this thread is getting ahead of itself.'],
        ],
        'callback' => [
            ['voice' => 'reactionary_fan', 'family' => 'local_callback', 'opening' => 'callback', 'slang' => '', 'emoji' => '😭', 'actors' => ['fan', 'rival'], 'text' => 'you really said "{parent_excerpt}" and now you are moving different 😭'],
            ['voice' => 'casual_fan', 'family' => 'local_callback_casual', 'opening' => 'callback', 'slang' => 'nah', 'emoji' => '', 'actors' => ['fan', 'teammate'], 'text' => 'nah, that is not what you were saying a second ago.'],
            ['voice' => 'neutral_viewer', 'family' => 'local_callback_measured', 'opening' => 'callback', 'slang' => '', 'emoji' => '', 'actors' => ['media', 'competition', 'club'], 'text' => 'That is not quite what you were saying in the first post.'],
        ],
    ];

    public function __construct(private readonly EchoService $echo = new EchoService()) {}

    public function initializeSchema(DatabaseInterface $database): void
    {
        SchemaInitializationGuard::run($database->connection(), self::class, function () use ($database): void {
            $connection = $database->connection();
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::SOURCES . ' (source_key TEXT PRIMARY KEY, player_id TEXT NOT NULL, occurred_date TEXT NOT NULL, kind TEXT NOT NULL, importance TEXT NOT NULL, context_json TEXT NOT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_sources_player_date ON ' . self::SOURCES . ' (player_id, occurred_date DESC, source_key DESC)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::POSTS . ' (id TEXT PRIMARY KEY, player_id TEXT NOT NULL, source_key TEXT NOT NULL, actor_type TEXT NOT NULL, actor_id TEXT NOT NULL, actor_name TEXT NOT NULL, occurred_date TEXT NOT NULL, post_text TEXT NOT NULL, engagement INTEGER NOT NULL DEFAULT 0, UNIQUE (source_key, actor_type, actor_id))');
            $columns = $connection->query('PRAGMA table_info(' . self::POSTS . ')')->fetchAll(PDO::FETCH_ASSOC);
            if (!in_array('identity_id', array_column($columns, 'name'), true)) {
                $connection->exec('ALTER TABLE ' . self::POSTS . ' ADD COLUMN identity_id TEXT NULL');
            }
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_posts_player_date ON ' . self::POSTS . ' (player_id, occurred_date DESC, id DESC)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_posts_identity_date ON ' . self::POSTS . ' (player_id, identity_id, occurred_date DESC, id DESC)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::THREAD_EDGES . ' (post_id TEXT PRIMARY KEY, player_id TEXT NOT NULL, source_key TEXT NOT NULL, parent_post_id TEXT NULL, quote_post_id TEXT NULL, thread_root_id TEXT NOT NULL, depth INTEGER NOT NULL, intent TEXT NOT NULL, pattern_family TEXT NOT NULL, voice TEXT NOT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_threads_player_root ON ' . self::THREAD_EDGES . ' (player_id, thread_root_id, depth, post_id)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::AUDIENCE . ' (player_id TEXT PRIMARY KEY, audience_score INTEGER NOT NULL DEFAULT 0, updated_date TEXT NOT NULL)');
            $connection->exec('CREATE TABLE IF NOT EXISTS ' . self::RESPONSES . ' (source_key TEXT PRIMARY KEY, player_id TEXT NOT NULL, status TEXT NOT NULL, choices_json TEXT NOT NULL, selected_id TEXT NULL, response_text TEXT NULL, created_date TEXT NOT NULL, resolved_date TEXT NULL)');
            $connection->exec('CREATE INDEX IF NOT EXISTS idx_pulse_responses_player_status ON ' . self::RESPONSES . ' (player_id, status, created_date DESC)');
        });
    }

    public function initializePlayer(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, int $startingScore = 4): void
    {
        $this->initializeSchema($database);
        $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::AUDIENCE . ' (player_id, audience_score, updated_date) VALUES (:player_id, :score, :date)');
        $statement->execute(['player_id' => $this->id($playerId), 'score' => max(0, min(100, $startingScore)), 'date' => $date->toIsoString()]);
    }

    /** @return array<string, mixed> */
    public function context(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        $id = $this->id($playerId);
        $score = null;
        if ($this->available($database, self::AUDIENCE)) {
            $statement = $database->connection()->prepare('SELECT audience_score FROM ' . self::AUDIENCE . ' WHERE player_id = :player_id');
            $statement->execute(['player_id' => $id]);
            $value = $statement->fetchColumn();
            $score = $value === false ? null : (int) $value;
        }
        if ($score === null) {
            $score = $this->fallbackAudienceScore($database, $id);
        }
        $score = max(0, min(100, $score));

        return ['audience_score' => $score, 'audience_band' => $this->audienceBand($score), 'followers' => $this->followers($score), 'followers_label' => $this->followersLabel($this->followers($score))];
    }

    /** @return list<array<string, mixed>> */
    public function feed(DatabaseInterface $database, PlayerId|string $playerId, int $limit = 30): array
    {
        if (!$this->available($database, self::POSTS)) {
            return [];
        }
        $identityField = $this->postColumnAvailable($database, 'identity_id') ? 'p.identity_id' : 'NULL';
        $edgeFields = 'NULL AS parent_post_id, NULL AS quote_post_id, NULL AS thread_root_id, 0 AS thread_depth, NULL AS thread_intent, NULL AS thread_pattern_family, NULL AS thread_voice';
        $edgeJoin = '';
        $edgeOrder = 'p.id DESC';
        if ($this->available($database, self::THREAD_EDGES)) {
            $edgeFields = 'e.parent_post_id, e.quote_post_id, e.thread_root_id, e.depth AS thread_depth, e.intent AS thread_intent, e.pattern_family AS thread_pattern_family, e.voice AS thread_voice';
            $edgeJoin = ' LEFT JOIN ' . self::THREAD_EDGES . ' e ON e.post_id = p.id';
            $edgeOrder = 'COALESCE(e.thread_root_id, p.id) DESC, CASE WHEN e.post_id IS NULL THEN 0 ELSE e.depth END ASC, p.id DESC';
        }
        $statement = $database->connection()->prepare('SELECT p.*, ' . $identityField . ' AS identity_id, s.kind, s.importance, s.context_json, ' . $edgeFields . ' FROM ' . self::POSTS . ' p JOIN ' . self::SOURCES . ' s ON s.source_key = p.source_key' . $edgeJoin . ' WHERE p.player_id = :player_id ORDER BY p.occurred_date DESC, ' . $edgeOrder . ' LIMIT :limit');
        $statement->bindValue(':player_id', $this->id($playerId));
        $statement->bindValue(':limit', max(1, min(self::MAX_SOURCES, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(function (array $row): array {
            $context = json_decode((string) $row['context_json'], true);
            $identity = $this->identityById($row['identity_id'] === null ? null : (string) $row['identity_id']);
            return ['id' => (string) $row['id'], 'source_key' => (string) $row['source_key'], 'date' => (string) $row['occurred_date'], 'actor_type' => (string) $row['actor_type'], 'actor_name' => (string) $row['actor_name'], 'identity_id' => $identity['id'] ?? null, 'identity_name' => $identity['name'] ?? null, 'identity_handle' => $identity['handle'] ?? null, 'identity_culture' => $identity['culture'] ?? 'global', 'identity_club' => $identity['club'] ?? null, 'text' => (string) $row['post_text'], 'engagement' => (int) $row['engagement'], 'kind' => (string) $row['kind'], 'importance' => (string) $row['importance'], 'parent_id' => $row['parent_post_id'] === null ? null : (string) $row['parent_post_id'], 'quote_id' => $row['quote_post_id'] === null ? null : (string) $row['quote_post_id'], 'thread_root_id' => $row['thread_root_id'] === null ? (string) $row['id'] : (string) $row['thread_root_id'], 'depth' => (int) ($row['thread_depth'] ?? 0), 'intent' => $row['thread_intent'] === null ? null : (string) $row['thread_intent'], 'pattern_family' => $row['thread_pattern_family'] === null ? null : (string) $row['thread_pattern_family'], 'voice' => $row['thread_voice'] === null ? null : (string) $row['thread_voice'], 'context' => is_array($context) ? $context : []];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function pendingResponse(DatabaseInterface $database, PlayerId|string $playerId): ?array
    {
        if (!$this->available($database, self::RESPONSES)) {
            return null;
        }
        $statement = $database->connection()->prepare('SELECT * FROM ' . self::RESPONSES . ' WHERE player_id = :player_id AND status = :status ORDER BY created_date ASC, source_key ASC LIMIT 1');
        $statement->execute(['player_id' => $this->id($playerId), 'status' => 'pending']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $choices = json_decode((string) $row['choices_json'], true);

        return ['source_key' => (string) $row['source_key'], 'status' => (string) $row['status'], 'created_date' => (string) $row['created_date'], 'choices' => is_array($choices) ? $choices : []];
    }

    /** Resolve one curated response exactly once. */
    public function respond(DatabaseInterface $database, PlayerId|string $playerId, string $sourceKey, string $choiceId, SimulationDate $date): bool
    {
        if (!$this->available($database, self::RESPONSES)) {
            return false;
        }
        $id = $this->id($playerId);
        return $database->transaction(function () use ($database, $id, $sourceKey, $choiceId, $date): bool {
            $query = $database->connection()->prepare('SELECT * FROM ' . self::RESPONSES . ' WHERE source_key = :source_key AND player_id = :player_id');
            $query->execute(['source_key' => $sourceKey, 'player_id' => $id]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) $row['status'] !== 'pending') {
                return false;
            }
            $choices = json_decode((string) $row['choices_json'], true);
            $selected = null;
            foreach (is_array($choices) ? $choices : [] as $choice) {
                if (is_array($choice) && (string) ($choice['id'] ?? '') === $choiceId) { $selected = $choice; break; }
            }
            if (!is_array($selected)) {
                return false;
            }
            $text = (string) ($selected['text'] ?? 'I will let the football do the talking.');
            $update = $database->connection()->prepare('UPDATE ' . self::RESPONSES . ' SET status = :status, selected_id = :selected_id, response_text = :response_text, resolved_date = :resolved_date WHERE source_key = :source_key AND player_id = :player_id AND status = :pending');
            $update->execute(['status' => 'resolved', 'selected_id' => $choiceId, 'response_text' => $text, 'resolved_date' => $date->toIsoString(), 'source_key' => $sourceKey, 'player_id' => $id, 'pending' => 'pending']);
            if ($update->rowCount() === 0) { return false; }
            $playerName = $this->playerName($database, $id);
            $this->insertPost($database, $id, 'response|' . $sourceKey . '|' . $choiceId, $sourceKey, 'player', $id, $playerName, $date, $text, $this->engagement($database, $id, 'notable', 'player'));

            return true;
        });
    }

    public function recordMatch(DatabaseInterface $database, GameMatch $match, PlayerMatchStat $stat): void
    {
        $this->initializeSchema($database);
        $database->transaction(function () use ($database, $match, $stat): void {
            $this->recordMatchInTransaction($database, $match, $stat);
        });
    }

    public function recordMatchInTransaction(DatabaseInterface $database, GameMatch $match, PlayerMatchStat $stat): void
    {
        if (!$stat->appeared()) { return; }
        $story = (new MatchStoryService())->playerStory($database, $match, $stat->playerId());
        $result = $match->result();
        $home = $result?->homeGoals() ?? 0;
        $away = $result?->awayGoals() ?? 0;
        $for = $stat->clubId()->value() === $match->homeClubId()->value() ? $home : $away;
        $against = $stat->clubId()->value() === $match->homeClubId()->value() ? $away : $home;
        $competition = $this->competitionName($database, $match->competitionId()->value());
        $type = $this->competitionType($database, $match->competitionId()->value());
        $fixtureContext = is_array($story['fixture_context'] ?? null) ? $story['fixture_context'] : [];
        $facts = ['appeared' => true, 'goals' => $stat->goals(), 'assists' => $stat->assists(), 'red_cards' => $stat->redCards(), 'rating' => $story['rating'], 'saves' => $stat->saves(), 'tackles' => $stat->tackles(), 'interceptions' => $stat->interceptions(), 'blocks' => $stat->blocks(), 'player_of_match' => ($story['player_of_match'] ?? false) === true, 'decisive' => is_array($story['decisive_contribution'] ?? null), 'rivalry' => ($fixtureContext['is_rivalry'] ?? false) === true, 'derby' => ($fixtureContext['is_derby'] ?? false) === true, 'important_match' => $type !== 'domestic_league' || $match->round() >= 3, 'result' => $for > $against ? 'win' : ($for === $against ? 'draw' : 'loss')];
        $route = $this->echo->match($facts);
        if ($route === null) { return; }
        $playerId = $stat->playerId()->value();
        $team = $this->clubName($database, $stat->clubId()->value());
        $opponentId = $stat->clubId()->value() === $match->homeClubId()->value() ? $match->awayClubId()->value() : $match->homeClubId()->value();
        $opponent = $this->clubName($database, $opponentId);
        $context = ['player' => $this->playerName($database, $playerId), 'team' => $team, 'opponent' => $opponent, 'competition' => $competition, 'competition_type' => $type, 'score' => $home . '-' . $away, 'result' => $facts['result'], 'goals' => $stat->goals(), 'assists' => $stat->assists(), 'rating' => $story['rating'], 'minutes' => $stat->minutes(), 'saves' => $stat->saves(), 'red_cards' => $stat->redCards(), 'fixture_context' => (string) ($fixtureContext['display_label'] ?? ''), 'rivalry' => $facts['rivalry'], 'derby' => $facts['derby'], 'source_match_id' => $match->id()->value(), 'club_id' => $stat->clubId()->value(), 'opponent_club_id' => $opponentId, 'club_country' => $this->clubCountry($database, $stat->clubId()->value()), 'opponent_country' => $this->clubCountry($database, $opponentId), 'competition_country' => $this->competitionCountry($database, $match->competitionId()->value()), 'player_nationality' => $this->playerNationality($database, $playerId), 'international' => $type === 'international'];
        $actors = [['type' => 'fan', 'id' => 'supporters:' . $stat->clubId()->value(), 'name' => 'Supporters', 'kind' => $route['kind']]];
        if (in_array($route['kind'], ['match_goal', 'match_assist', 'match_decisive_goal', 'match_major_contribution', 'match_strong_performance', 'match_red_card'], true)) {
            $actors[] = ['type' => 'media', 'id' => 'media:matchday-desk', 'name' => 'Matchday Desk', 'kind' => $route['kind']];
        }
        if ($route['importance'] !== 'routine') {
            $actors[] = ['type' => 'competition', 'id' => 'competition:' . $match->competitionId()->value(), 'name' => $competition, 'kind' => $route['kind']];
            $actors[] = ['type' => $type === 'international' ? 'national' : 'club', 'id' => $type === 'international' ? 'national:' . $stat->clubId()->value() : 'club:' . $stat->clubId()->value(), 'name' => $type === 'international' ? 'National Team' : $team, 'kind' => $route['kind']];
        }
        $related = $this->relatedPlayer($database, $match, $stat, $route['kind']);
        if ($related !== null) {
            $actors[] = ['type' => $related['type'], 'id' => 'player:' . $related['id'], 'name' => $related['name'], 'kind' => $route['kind']];
        }
        $this->recordSourceInTransaction($database, $playerId, 'match:' . $match->id()->value() . ':player:' . $playerId, $match->scheduledDate(), (string) $route['kind'], (string) $route['importance'], $context, $actors, $route['response'] === null ? null : $this->responseChoices((string) $route['response']));
    }

    public function recordTransfer(DatabaseInterface $database, PlayerId|string $playerId, ?string $oldClubId, ?string $newClubId, SimulationDate $date, string $kind = 'transfer'): void
    {
        $this->initializeSchema($database);
        $route = $this->echo->transfer($kind, $oldClubId === null || $oldClubId === '');
        if ($route['importance'] === 'routine') {
            return;
        }
        if ($route['kind'] === 'contract_event') {
            $route['kind'] = 'transfer';
        }
        $id = $this->id($playerId);
        $database->transaction(function () use ($database, $id, $oldClubId, $newClubId, $date, $route): void {
            $old = $oldClubId === null || $oldClubId === '' ? 'free agency' : $this->clubName($database, $oldClubId);
            $new = $newClubId === null || $newClubId === '' ? 'free agency' : $this->clubName($database, $newClubId);
            $context = ['player' => $this->playerName($database, $id), 'from_club' => $old, 'team' => $new, 'headline' => $route['kind'] === 'transfer_request' ? 'a transfer request' : 'a new Club chapter', 'club_id' => $newClubId, 'old_club_id' => $oldClubId, 'club_country' => $newClubId === null || $newClubId === '' ? null : $this->clubCountry($database, $newClubId), 'from_country' => $oldClubId === null || $oldClubId === '' ? null : $this->clubCountry($database, $oldClubId), 'competition_country' => null, 'player_nationality' => $this->playerNationality($database, $id), 'transfer_cross_border' => $oldClubId !== null && $oldClubId !== '' && $newClubId !== null && $newClubId !== '' && $this->clubCountry($database, $oldClubId) !== $this->clubCountry($database, $newClubId)];
            $actors = [['type' => 'media', 'id' => 'media:market-desk', 'name' => 'Market Desk', 'kind' => $route['kind']]];
            if ($newClubId !== null && $newClubId !== '') { $actors[] = ['type' => 'club', 'id' => 'club:' . $newClubId, 'name' => $new, 'kind' => $route['kind']]; }
            $this->recordSourceInTransaction($database, $id, 'transfer:' . $route['kind'] . ':' . $id . ':' . $date->toIsoString(), $date, (string) $route['kind'], (string) $route['importance'], $context, $actors, $route['response'] === null ? null : $this->responseChoices('transfer'));
        });
    }

    /** @param array<string, mixed> $facts */
    public function recordAchievement(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $source, string $headline, string $importance = 'major', ?string $clubId = null): void
    {
        $this->initializeSchema($database);
        $database->transaction(function () use ($database, $playerId, $date, $source, $headline, $importance, $clubId): void {
            $this->recordAchievementInTransaction($database, $playerId, $date, $source, $headline, $importance, $clubId);
        });
    }

    public function recordAchievementInTransaction(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $source, string $headline, string $importance = 'major', ?string $clubId = null): void
    {
        $id = $this->id($playerId);
        $route = $this->echo->achievement(['source' => $source, 'headline' => $headline, 'importance' => $importance]);
        $context = ['player' => $this->playerName($database, $id), 'headline' => $headline, 'team' => $clubId === null ? 'the national team' : $this->clubName($database, $clubId), 'club_id' => $clubId, 'club_country' => $clubId === null ? null : $this->clubCountry($database, $clubId), 'competition_country' => null, 'player_nationality' => $this->playerNationality($database, $id), 'international' => str_contains(strtolower($source), 'international')];
        $international = str_contains(strtolower($source), 'international');
        $actors = [['type' => 'fan', 'id' => 'supporters:achievement', 'name' => 'Supporters', 'kind' => $route['kind']], ['type' => 'media', 'id' => 'media:football-desk', 'name' => 'Football Desk', 'kind' => $route['kind']]];
        if ($international) { $actors[] = ['type' => 'national', 'id' => 'national:achievement', 'name' => 'National Team', 'kind' => $route['kind']]; }
        if ($clubId !== null && $clubId !== '') { $actors[] = ['type' => 'club', 'id' => 'club:' . $clubId, 'name' => $this->clubName($database, $clubId), 'kind' => $route['kind']]; }
        $responseContext = $route['kind'] === 'retirement' ? 'retirement' : 'achievement';
        $this->recordSourceInTransaction($database, $id, 'achievement:' . $source, $date, (string) $route['kind'], (string) $route['importance'], $context, $actors, $this->responseChoices($responseContext));
    }

    /** @param array<string, mixed> $payload */
    public function recordAvailabilityChange(DatabaseInterface $database, array $payload, string $event): void
    {
        $playerId = (string) ($payload['player_id'] ?? '');
        $dateValue = (string) ($event === 'player.recovered' ? ($payload['actual_recovery_date'] ?? '') : ($payload['start_date'] ?? ''));
        $injuryId = (string) ($payload['id'] ?? '');
        if ($playerId === '' || $dateValue === '' || $injuryId === '') { return; }
        $route = $this->echo->availability($event);
        if ($route['importance'] === 'routine') { return; }
        $this->initializeSchema($database);
        $date = SimulationDate::fromIsoString($dateValue);
        $database->transaction(function () use ($database, $playerId, $date, $injuryId, $route, $payload, $event): void {
            $clubId = $this->currentClub($database, $playerId);
            $team = $clubId === null ? 'the football world' : $this->clubName($database, $clubId);
            $context = ['player' => $this->playerName($database, $playerId), 'team' => $team, 'headline' => $route['kind'] === 'injury' ? 'an injury' : 'a return from injury', 'injury' => (string) ($payload['category'] ?? 'recorded injury'), 'club_id' => $clubId, 'club_country' => $clubId === null ? null : $this->clubCountry($database, $clubId), 'competition_country' => null, 'player_nationality' => $this->playerNationality($database, $playerId)];
            $actors = [['type' => 'fan', 'id' => 'supporters:' . ($clubId ?? 'football'), 'name' => 'Supporters', 'kind' => $route['kind']], ['type' => 'media', 'id' => 'media:availability-desk', 'name' => 'Football Desk', 'kind' => $route['kind']]];
            if ($clubId !== null) { $actors[] = ['type' => 'club', 'id' => 'club:' . $clubId, 'name' => $team, 'kind' => $route['kind']]; }
            $this->recordSourceInTransaction($database, $playerId, 'availability:' . $event . ':' . $injuryId, $date, (string) $route['kind'], (string) $route['importance'], $context, $actors, null);
        });
    }

    /** @param array<string, mixed> $choice */
    public function recordCareerChoiceInTransaction(DatabaseInterface $database, PlayerId|string $playerId, SimulationDate $date, string $source, string $category, bool $newsworthy, array $choice): void
    {
        $route = $this->echo->careerChoice($category, $newsworthy);
        if ($route['importance'] === 'routine' && !($choice['social']['history'] ?? false)) { return; }
        $id = $this->id($playerId);
        $clubId = $this->currentClub($database, $id);
        $context = ['player' => $this->playerName($database, $id), 'headline' => (string) ($choice['history'] ?? 'A Career choice changed the football context.'), 'club_id' => $clubId, 'club_country' => $clubId === null ? null : $this->clubCountry($database, $clubId), 'competition_country' => null, 'player_nationality' => $this->playerNationality($database, $id)];
        $this->recordSourceInTransaction($database, $id, 'career-choice:' . $source, $date, 'career_choice', (string) $route['importance'], $context, [['type' => 'teammate', 'id' => 'teammates:career', 'name' => 'Teammates', 'kind' => 'career_choice']], null);
    }

    /** @return array<string, bool|int> */
    public function integrity(DatabaseInterface $database, PlayerId|string $playerId): array
    {
        if (!$this->available($database, self::SOURCES) || !$this->available($database, self::POSTS) || !$this->available($database, self::RESPONSES)) {
            return ['valid' => true, 'sources' => 0, 'posts' => 0, 'retention' => true, 'pending' => 0, 'invalid_actors' => 0, 'invalid_threads' => 0];
        }
        $id = $this->id($playerId);
        $sources = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::SOURCES . ' WHERE player_id = :player_id'); $sources->execute(['player_id' => $id]);
        $posts = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::POSTS . ' WHERE player_id = :player_id'); $posts->execute(['player_id' => $id]);
        $pending = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::RESPONSES . ' WHERE player_id = :player_id AND status = :status'); $pending->execute(['player_id' => $id, 'status' => 'pending']);
        $orphan = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::POSTS . ' p LEFT JOIN ' . self::SOURCES . ' s ON s.source_key = p.source_key WHERE p.player_id = :player_id AND s.source_key IS NULL'); $orphan->execute(['player_id' => $id]);
        $sourceCount = (int) $sources->fetchColumn(); $postCount = (int) $posts->fetchColumn(); $pendingCount = (int) $pending->fetchColumn();
        $invalidActor = 0;
        if ($this->available($database, 'player_records')) {
            $actorCheck = $database->connection()->prepare("SELECT COUNT(*) FROM " . self::POSTS . " p LEFT JOIN player_records players ON players.id = CASE WHEN p.actor_type = 'player' THEN p.actor_id WHEN instr(p.actor_id, '|thread|') > 0 THEN substr(substr(p.actor_id, 8), 1, instr(p.actor_id, '|thread|') - 8) ELSE substr(p.actor_id, 8) END WHERE p.player_id = :player_id AND p.actor_type IN ('player', 'teammate', 'rival') AND players.id IS NULL");
            $actorCheck->execute(['player_id' => $id]);
            $invalidActor = (int) $actorCheck->fetchColumn();
        }
        $invalidThreads = 0;
        if ($this->available($database, self::THREAD_EDGES)) {
            $threadCheck = $database->connection()->prepare('SELECT COUNT(*) FROM ' . self::THREAD_EDGES . ' e LEFT JOIN ' . self::POSTS . ' child ON child.id = e.post_id LEFT JOIN ' . self::POSTS . ' parent ON parent.id = e.parent_post_id LEFT JOIN ' . self::POSTS . ' quote ON quote.id = e.quote_post_id LEFT JOIN ' . self::POSTS . ' root ON root.id = e.thread_root_id WHERE e.player_id = :player_id AND (child.id IS NULL OR root.id IS NULL OR (e.parent_post_id IS NOT NULL AND parent.id IS NULL) OR (e.quote_post_id IS NOT NULL AND quote.id IS NULL) OR e.depth < 1 OR e.depth > ' . self::MAX_THREAD_DEPTH . ')');
            $threadCheck->execute(['player_id' => $id]);
            $invalidThreads = (int) $threadCheck->fetchColumn();
        }

        return ['valid' => $sourceCount <= self::MAX_SOURCES && $pendingCount <= 1 && (int) $orphan->fetchColumn() === 0 && $invalidActor === 0 && $invalidThreads === 0, 'sources' => $sourceCount, 'posts' => $postCount, 'retention' => $sourceCount <= self::MAX_SOURCES, 'pending' => $pendingCount, 'invalid_actors' => $invalidActor, 'invalid_threads' => $invalidThreads];
    }

    /** @return array<string, int> */
    public function templateCounts(): array
    {
        $counts = [];
        foreach (self::TEMPLATES as $type => $groups) { $counts[$type] = array_sum(array_map('count', $groups)); }

        return $counts;
    }

    private function recordSourceInTransaction(DatabaseInterface $database, string $playerId, string $sourceKey, SimulationDate $date, string $kind, string $importance, array $context, array $actors, ?array $choices): void
    {
        $actors = $this->assignIdentities($database, $playerId, $kind, $sourceKey, $context, $actors);
        $recent = $this->recentReactionHistory($database, $playerId);
        $used = ['texts' => [], 'families' => [], 'openings' => [], 'slang' => [], 'emojis' => []];
        $reactions = [];
        $reactionMeta = [];
        $identityMeta = [];
        foreach ($actors as $actor) {
            if (!is_array($actor)) { continue; }
            $actorType = (string) ($actor['type'] ?? 'fan');
            $actorId = (string) ($actor['id'] ?? 'unknown');
            $identity = is_array($actor['identity'] ?? null) ? $actor['identity'] : null;
            $reactionContext = $context;
            if ($identity !== null) {
                $reactionContext['pulse_identity_id'] = (string) ($identity['id'] ?? '');
                $reactionContext['pulse_identity_name'] = (string) ($identity['name'] ?? '');
                $reactionContext['pulse_identity_handle'] = (string) ($identity['handle'] ?? '');
                $reactionContext['pulse_culture'] = (string) ($identity['culture'] ?? 'global');
                $reactionContext['pulse_voice'] = (string) ($identity['voice'] ?? '');
                $reactionContext['pulse_memory'] = $this->recentIdentityMemory($database, $playerId, (string) ($identity['id'] ?? ''));
                $identityMeta[$actorType . '|' . $actorId] = $identity;
            }
            $reaction = $this->selectReaction((string) ($actor['kind'] ?? $kind), $actorType, $reactionContext, $sourceKey, $actorId, $recent, $used);
            $reactionKey = $actorType . '|' . $actorId;
            if ($identity !== null) { $reaction['meta']['identity_id'] = (string) ($identity['id'] ?? ''); }
            $reactionMeta[$reactionKey] = $reaction['meta'];
            $reactions[] = ['actor' => $actor, 'text' => $reaction['text']];
            $used['texts'][$reaction['text']] = true;
            foreach (['families', 'openings', 'slang', 'emojis'] as $dimension) {
                $value = (string) ($reaction['meta'][$dimension] ?? '');
                if ($value !== '') { $used[$dimension][$value] = true; }
            }
        }
        $context['reaction_meta'] = $reactionMeta;
        if ($identityMeta !== []) { $context['identity_meta'] = $identityMeta; }
        $threadPlan = $this->selectThreadPlan($kind, $actors, $sourceKey, $this->recentThreadPatterns($database, $playerId));
        if ($threadPlan !== null) { $context['thread_meta'] = $threadPlan; }
        $marker = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::SOURCES . ' (source_key, player_id, occurred_date, kind, importance, context_json) VALUES (:source_key, :player_id, :date, :kind, :importance, :context)');
        $marker->execute(['source_key' => $sourceKey, 'player_id' => $playerId, 'date' => $date->toIsoString(), 'kind' => $kind, 'importance' => $importance, 'context' => json_encode($context, JSON_THROW_ON_ERROR)]);
        if ($marker->rowCount() === 0) { return; }
        $audience = $this->audienceScore($database, $playerId);
        $this->writeAudience($database, $playerId, $this->nextAudience($audience, $importance, $kind), $date);
        $rootPosts = [];
        foreach ($reactions as $reaction) {
            $actor = $reaction['actor'];
            $actorType = (string) ($actor['type'] ?? 'fan');
            $actorId = (string) ($actor['id'] ?? 'unknown');
            $identity = is_array($actor['identity'] ?? null) ? $actor['identity'] : null;
            $postId = $this->insertPost($database, $playerId, 'post|' . $sourceKey . '|' . $actorType . '|' . $actorId, $sourceKey, $actorType, $actorId, (string) ($actor['name'] ?? 'Football world'), $date, $reaction['text'], $this->engagement($database, $playerId, $importance, $actorType), $identity === null ? null : (string) ($identity['id'] ?? null));
            $rootPosts[] = ['id' => $postId, 'actor' => $actor, 'text' => $reaction['text']];
        }
        if ($threadPlan !== null && ($threadPlan['pattern'] ?? 'no_thread') !== 'no_thread') {
            $this->generateThreadInTransaction($database, $playerId, $sourceKey, $date, $importance, $context, $rootPosts, $threadPlan);
        }
        if ($choices !== null && $this->pendingResponse($database, $playerId) === null) {
            $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::RESPONSES . ' (source_key, player_id, status, choices_json, created_date) VALUES (:source_key, :player_id, :status, :choices, :date)');
            $statement->execute(['source_key' => $sourceKey, 'player_id' => $playerId, 'status' => 'pending', 'choices' => json_encode($choices, JSON_THROW_ON_ERROR), 'date' => $date->toIsoString()]);
        }
        $this->prune($database, $playerId);
    }

    /** @param array<string, mixed> $context */
    private function insertPost(DatabaseInterface $database, string $playerId, string $id, string $sourceKey, string $actorType, string $actorId, string $actorName, SimulationDate $date, string $text, int $engagement, ?string $identityId = null): string
    {
        $postId = $this->postId($id);
        $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::POSTS . ' (id, player_id, source_key, actor_type, actor_id, actor_name, occurred_date, post_text, engagement, identity_id) VALUES (:id, :player_id, :source_key, :actor_type, :actor_id, :actor_name, :date, :text, :engagement, :identity_id)');
        $statement->execute(['id' => $postId, 'player_id' => $playerId, 'source_key' => $sourceKey, 'actor_type' => $actorType, 'actor_id' => $actorId, 'actor_name' => $actorName, 'date' => $date->toIsoString(), 'text' => $text, 'engagement' => max(0, $engagement), 'identity_id' => $identityId]);

        return $postId;
    }

    /** @param array<string, mixed> $context */
    private function render(string $kind, string $actorType, array $context, string $sourceKey): string
    {
        $templates = self::TEMPLATES[$actorType] ?? self::TEMPLATES['fan'];
        $options = $templates[$kind] ?? $templates['match_major_contribution'] ?? $templates['career_choice'] ?? self::TEMPLATES['fan']['career_choice'];
        $index = hexdec(substr(hash('sha256', 'pulse-feed:v1|' . $sourceKey . '|' . $actorType . '|' . $kind), 0, 8)) % count($options);

        return $this->interpolate($options[$index], $context);
    }

    /** @return array{pattern:string,intent:string,quote:bool,reply_index:int,callback_index:int|null}|null */
    private function selectThreadPlan(string $kind, array $actors, string $sourceKey, array $recentPatterns): ?array
    {
        if (!in_array($kind, ['match_goal', 'match_assist', 'match_decisive_goal', 'match_major_contribution', 'match_strong_performance', 'match_result', 'match_red_card', 'transfer', 'free_agent_signing', 'transfer_request', 'award', 'honour', 'record', 'milestone', 'retirement'], true)) {
            return null;
        }
        $patterns = [
            ['pattern' => 'no_thread', 'intent' => '', 'quote' => false, 'reply_intent' => '', 'callback' => false],
            ['pattern' => 'agreement', 'intent' => 'agree', 'quote' => false, 'reply_intent' => 'agree', 'callback' => false],
            ['pattern' => 'pushback', 'intent' => 'disagree', 'quote' => false, 'reply_intent' => 'disagree', 'callback' => false],
            ['pattern' => 'rival_banter', 'intent' => 'rival_banter', 'quote' => true, 'reply_intent' => 'rival_banter', 'callback' => false],
            ['pattern' => 'reluctant_credit', 'intent' => 'reluctant_agreement', 'quote' => false, 'reply_intent' => 'reluctant_agreement', 'callback' => false],
            ['pattern' => 'defence_callback', 'intent' => 'defend_player', 'quote' => false, 'reply_intent' => 'defend_player', 'callback' => true],
        ];
        $available = [];
        foreach ($patterns as $pattern) {
            if ($pattern['pattern'] === 'no_thread') {
                $available[] = $pattern + ['reply_index' => null, 'callback_index' => null];
                continue;
            }
            $replyIndex = $this->threadActorIndex($actors, (string) $pattern['reply_intent'], [0]);
            if ($replyIndex === null) { continue; }
            $callbackIndex = null;
            if ($pattern['callback']) {
                $callbackIndex = $this->threadActorIndex($actors, 'callback', [0, $replyIndex]);
                if ($callbackIndex === null) { continue; }
            }
            $available[] = $pattern + ['reply_index' => $replyIndex, 'callback_index' => $callbackIndex];
        }
        if (count($available) <= 1) { return null; }
        $start = hexdec(substr(hash('sha256', 'pulse-thread:v1|' . $sourceKey . '|' . $kind), 0, 8)) % count($available);
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($available as $offset => $candidate) {
            $candidate = $available[($start + $offset) % count($available)];
            $score = isset($recentPatterns[$candidate['pattern']]) ? 100 : 0;
            if ($candidate['pattern'] === 'no_thread') { $score += 1; }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
                if ($score === 0) { break; }
            }
        }
        if ($best === null || $best['pattern'] === 'no_thread') { return ['pattern' => 'no_thread', 'intent' => '', 'quote' => false, 'reply_index' => 0, 'callback_index' => null]; }

        return ['pattern' => (string) $best['pattern'], 'intent' => (string) $best['intent'], 'quote' => (bool) $best['quote'], 'reply_index' => (int) $best['reply_index'], 'callback_index' => $best['callback_index'] === null ? null : (int) $best['callback_index']];
    }

    private function threadActorIndex(array $actors, string $intent, array $excluded): ?int
    {
        $preference = match ($intent) {
            'reluctant_agreement', 'rival_banter' => ['rival', 'fan', 'media', 'club', 'teammate', 'competition'],
            'callback' => ['fan', 'rival', 'teammate', 'club', 'media'],
            'defend_player' => ['teammate', 'fan', 'club', 'rival'],
            default => ['fan', 'media', 'club', 'teammate', 'rival', 'competition'],
        };
        foreach ($preference as $type) {
            foreach ($actors as $index => $actor) {
                if (in_array($index, $excluded, true) || !is_array($actor) || (string) ($actor['type'] ?? '') !== $type) { continue; }
                if ($this->threadCandidateAvailable($intent, $type)) { return $index; }
            }
        }

        return null;
    }

    private function threadCandidateAvailable(string $intent, string $actorType): bool
    {
        foreach (self::THREAD_CATALOG[$intent] ?? [] as $candidate) {
            if (in_array($actorType, $candidate['actors'], true)) { return true; }
        }

        return false;
    }

    /** @param array{pattern:string,intent:string,quote:bool,reply_index:int,callback_index:int|null} $plan */
    private function generateThreadInTransaction(DatabaseInterface $database, string $playerId, string $sourceKey, SimulationDate $date, string $importance, array $context, array $rootPosts, array $plan): void
    {
        if (count($rootPosts) < 2 || !$this->available($database, self::THREAD_EDGES)) { return; }
        $parent = $rootPosts[0];
        $replyRoot = $rootPosts[$plan['reply_index']] ?? null;
        if (!is_array($replyRoot)) { return; }
        $threadHistory = $this->recentThreadReactionHistory($database, $playerId);
        $used = ['texts' => [], 'families' => [], 'openings' => [], 'slang' => [], 'emojis' => []];
        $replyContext = $context;
        $replyContext['parent_excerpt'] = $this->excerpt((string) $parent['text']);
        $replyActor = $replyRoot['actor'];
        $replyType = (string) ($replyActor['type'] ?? 'fan');
        $replyIdentity = is_array($replyActor['identity'] ?? null) ? $replyActor['identity'] : null;
        if ($replyIdentity !== null) {
            $replyContext['pulse_identity_id'] = (string) ($replyIdentity['id'] ?? '');
            $replyContext['pulse_culture'] = (string) ($replyIdentity['culture'] ?? 'global');
            $replyContext['pulse_voice'] = (string) ($replyIdentity['voice'] ?? '');
        }
        $replyActorId = (string) ($replyActor['id'] ?? 'unknown') . '|thread|' . $plan['pattern'] . '|1';
        $reply = $this->selectThreadReaction((string) $plan['intent'], $replyType, $replyContext, $sourceKey, $replyActorId, $threadHistory, $used);
        $replyPostId = $this->insertPost($database, $playerId, 'thread|' . $sourceKey . '|' . $plan['pattern'] . '|1', $sourceKey, $replyType, $replyActorId, (string) ($replyActor['name'] ?? 'Football world'), $date, $reply['text'], $this->engagement($database, $playerId, $importance, $replyType), $replyIdentity === null ? null : (string) ($replyIdentity['id'] ?? null));
        $this->insertThreadEdge($database, $replyPostId, $playerId, $sourceKey, $plan['quote'] ? null : (string) $parent['id'], $plan['quote'] ? (string) $parent['id'] : null, (string) $parent['id'], 1, (string) $plan['intent'], $plan['pattern'], (string) $reply['meta']['voice']);
        $used['texts'][$reply['text']] = true;
        foreach (['families', 'openings', 'slang', 'emojis'] as $dimension) {
            $value = (string) ($reply['meta'][$dimension] ?? '');
            if ($value !== '') { $used[$dimension][$value] = true; }
        }
        if ($plan['callback_index'] === null) { return; }
        $callbackRoot = $rootPosts[$plan['callback_index']] ?? null;
        if (!is_array($callbackRoot)) { return; }
        $callbackActor = $callbackRoot['actor'];
        $callbackType = (string) ($callbackActor['type'] ?? 'fan');
        $callbackIdentity = is_array($callbackActor['identity'] ?? null) ? $callbackActor['identity'] : null;
        $callbackActorId = (string) ($callbackActor['id'] ?? 'unknown') . '|thread|' . $plan['pattern'] . '|2';
        $callbackContext = $context;
        $callbackContext['parent_excerpt'] = $this->excerpt((string) $reply['text']);
        if ($callbackIdentity !== null) {
            $callbackContext['pulse_identity_id'] = (string) ($callbackIdentity['id'] ?? '');
            $callbackContext['pulse_culture'] = (string) ($callbackIdentity['culture'] ?? 'global');
            $callbackContext['pulse_voice'] = (string) ($callbackIdentity['voice'] ?? '');
        }
        $callback = $this->selectThreadReaction('callback', $callbackType, $callbackContext, $sourceKey, $callbackActorId, $threadHistory, $used);
        $callbackPostId = $this->insertPost($database, $playerId, 'thread|' . $sourceKey . '|' . $plan['pattern'] . '|2', $sourceKey, $callbackType, $callbackActorId, (string) ($callbackActor['name'] ?? 'Football world'), $date, $callback['text'], $this->engagement($database, $playerId, $importance, $callbackType), $callbackIdentity === null ? null : (string) ($callbackIdentity['id'] ?? null));
        $this->insertThreadEdge($database, $callbackPostId, $playerId, $sourceKey, $replyPostId, null, (string) $parent['id'], 2, 'callback', $plan['pattern'], (string) $callback['meta']['voice']);
    }

    /** @param list<array<string, mixed>> $actors @return list<array<string, mixed>> */
    private function assignIdentities(DatabaseInterface $database, string $playerId, string $kind, string $sourceKey, array $context, array $actors): array
    {
        $used = [];
        $recentIdentities = $this->recentIdentityIds($database, $playerId);
        $assigned = [];
        foreach ($actors as $index => $actor) {
            if (!is_array($actor)) { continue; }
            $identity = $this->selectIdentity($context, (string) ($actor['type'] ?? 'fan'), $sourceKey, $index, $used, $recentIdentities);
            if ($identity !== null) {
                $actor['identity'] = $identity;
                $used[(string) $identity['id']] = true;
            }
            $assigned[] = $actor;
        }

        return $assigned;
    }

    /** @return array{id:string,name:string,handle:string,voice:string,culture:string,nation:string,club:?string}|null */
    private function selectIdentity(array $context, string $actorType, string $sourceKey, int $slot, array $used, array $recentIdentities = []): ?array
    {
        $allowedVoices = self::VOICES_BY_ACTOR[$actorType] ?? ['neutral_viewer'];
        $preferredCultures = [];
        $addCulture = function (?string $nation) use (&$preferredCultures): void {
            if ($nation === null || $nation === '') { return; }
            $culture = $this->cultureForNation($nation);
            if (!in_array($culture, $preferredCultures, true)) { $preferredCultures[] = $culture; }
        };
        if ($actorType === 'fan' || $actorType === 'teammate' || $actorType === 'club') {
            $addCulture((string) ($context['club_country'] ?? ''));
            $addCulture((string) ($context['competition_country'] ?? ''));
        }
        if ($actorType === 'national' || $actorType === 'fan') { $addCulture((string) ($context['player_nationality'] ?? '')); }
        if ($actorType === 'rival') { $addCulture((string) ($context['opponent_country'] ?? '')); }
        if ($actorType === 'competition' || $actorType === 'media') { $addCulture((string) ($context['competition_country'] ?? '')); }
        if ($preferredCultures === []) { $preferredCultures[] = 'global'; }

        $candidates = [];
        foreach (self::IDENTITY_CATALOG as $identity) {
            if (isset($used[$identity['id']]) || !in_array($identity['voice'], $allowedVoices, true)) { continue; }
            $score = 0;
            $culture = $this->cultureForNation($identity['culture']);
            $cultureIndex = array_search($culture, $preferredCultures, true);
            if ($cultureIndex !== false) { $score += 100 - ((int) $cultureIndex * 12); }
            if ($identity['culture'] === 'global') { $score += ($actorType === 'media' || $actorType === 'competition') ? 70 : 0; }
            if ($identity['club'] !== null && (string) ($context['club_id'] ?? '') === $identity['club']) { $score += 20; }
            if ($identity['nation'] !== 'global' && (string) ($context['player_nationality'] ?? '') === $identity['nation']) { $score += $actorType === 'national' ? 100 : 35; }
            if ($actorType === 'rival' && $identity['club'] !== null && (string) ($context['opponent_club_id'] ?? '') === $identity['club']) { $score += 120; }
            if (isset($recentIdentities[$identity['id']])) { $score -= 60; }
            $score += hexdec(substr(hash('sha256', 'pulse-identity-balance:v1|' . $sourceKey . '|' . $actorType . '|' . $identity['id']), 0, 8)) % 81;
            $tie = hexdec(substr(hash('sha256', 'pulse-identity:v1|' . $sourceKey . '|' . $actorType . '|' . $slot . '|' . $identity['id']), 0, 8));
            $candidates[] = ['score' => $score, 'tie' => $tie, 'identity' => $identity];
        }
        usort($candidates, static fn (array $left, array $right): int => $left['score'] === $right['score'] ? $left['tie'] <=> $right['tie'] : $right['score'] <=> $left['score']);

        return $candidates[0]['identity'] ?? null;
    }

    /** @return array{id:string,name:string,handle:string,voice:string,culture:string,nation:string,club:?string}|null */
    private function identityById(?string $identityId): ?array
    {
        if ($identityId === null || $identityId === '') { return null; }
        foreach (self::IDENTITY_CATALOG as $identity) {
            if ($identity['id'] === $identityId) { return $identity; }
        }

        return null;
    }

    private function cultureForNation(string $nation): string
    {
        $key = strtolower(trim(str_replace('_', '-', $nation)));

        return self::CULTURE_ALIASES[$key] ?? ($key === '' ? 'global' : 'global');
    }

    /** @return list<array{post_text:string,source_key:string,stance:string}> */
    private function recentIdentityMemory(DatabaseInterface $database, string $playerId, string $identityId): array
    {
        if ($identityId === '' || !$this->postColumnAvailable($database, 'identity_id')) { return []; }
        $statement = $database->connection()->prepare('SELECT p.post_text, p.source_key, s.context_json FROM ' . self::POSTS . ' p JOIN ' . self::SOURCES . ' s ON s.source_key = p.source_key WHERE p.player_id = :player_id AND p.identity_id = :identity_id ORDER BY p.occurred_date DESC, p.id DESC LIMIT 16');
        $statement->execute(['player_id' => $playerId, 'identity_id' => $identityId]);
        $memory = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sourceContext = json_decode((string) ($row['context_json'] ?? ''), true);
            $stance = 'neutral';
            $family = '';
            if (is_array($sourceContext)) {
                foreach ((array) ($sourceContext['identity_meta'] ?? []) as $key => $identity) {
                    if (!is_array($identity) || (string) ($identity['id'] ?? '') !== $identityId) { continue; }
                    $meta = $sourceContext['reaction_meta'][$key] ?? null;
                    if (is_array($meta)) { $stance = (string) ($meta['stance'] ?? 'neutral'); $family = (string) ($meta['family'] ?? ''); }
                    break;
                }
            }
            if (str_starts_with($family, 'memory|')) { continue; }
            $memory[] = ['post_text' => (string) ($row['post_text'] ?? ''), 'source_key' => (string) ($row['source_key'] ?? ''), 'stance' => $stance];
        }

        return $memory;
    }

    private function postColumnAvailable(DatabaseInterface $database, string $column): bool
    {
        if (!$this->available($database, self::POSTS)) { return false; }
        $columns = $database->connection()->query('PRAGMA table_info(' . self::POSTS . ')')->fetchAll(PDO::FETCH_ASSOC);

        return in_array($column, array_column($columns, 'name'), true);
    }

    /** @return array<string, bool> */
    private function recentIdentityIds(DatabaseInterface $database, string $playerId): array
    {
        if (!$this->postColumnAvailable($database, 'identity_id')) { return []; }
        $statement = $database->connection()->prepare('SELECT identity_id FROM ' . self::POSTS . ' WHERE player_id = :player_id AND identity_id IS NOT NULL ORDER BY occurred_date DESC, id DESC LIMIT 12');
        $statement->execute(['player_id' => $playerId]);

        return array_fill_keys(array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)))), true);
    }

    /** @return list<array<string, mixed>> */
    private function cultureCandidates(string $kind, string $actorType, array $context, string $sourceKey): array
    {
        $culture = $this->cultureForNation((string) ($context['pulse_culture'] ?? ''));
        if ($culture === 'global' || !isset(self::CULTURE_REACTIONS[$culture])) { return []; }
        $bucket = $this->cultureBucket($kind, $context);
        $candidates = self::CULTURE_REACTIONS[$culture][$bucket] ?? self::CULTURE_REACTIONS[$culture]['positive'] ?? [];
        $nativeAllowed = hexdec(substr(hash('sha256', 'pulse-native:v1|' . $sourceKey . '|' . $culture), 0, 8)) % 4 === 0;
        $result = [];
        foreach ($candidates as $candidate) {
            if (($candidate['native'] ?? false) === true && !$nativeAllowed) { continue; }
            $text = (string) $candidate['text'];
            if ($kind === 'match_red_card') { $text .= ' The red card changed the night.'; }
            $result[] = [
                'voice' => (string) ($context['pulse_voice'] ?? 'neutral_viewer'),
                'family' => 'culture|' . $culture . '|' . $candidate['family'],
                'opening' => $candidate['opening'],
                'slang' => $candidate['slang'],
                'emoji' => $candidate['emoji'],
                'actors' => [$actorType],
                'results' => [],
                'text' => $text,
                'stance' => $bucket === 'negative' ? 'criticism' : ($bucket === 'major' || $bucket === 'positive' ? 'praise' : 'neutral'),
                'reference' => $candidate['reference'],
            ];
        }

        return $result;
    }

    private function cultureBucket(string $kind, array $context): string
    {
        if (in_array($kind, ['match_red_card', 'injury'], true) || (string) ($context['result'] ?? '') === 'loss') { return 'negative'; }
        if (in_array($kind, ['match_decisive_goal', 'award', 'honour', 'record', 'milestone', 'transfer', 'free_agent_signing', 'retirement'], true)) { return 'major'; }

        return 'positive';
    }

    /** @return array<string, mixed>|null */
    private function memoryCandidate(string $kind, array $context, string $sourceKey): ?array
    {
        $memory = $context['pulse_memory'] ?? [];
        if (!is_array($memory) || $memory === []) { return null; }
        if (hexdec(substr(hash('sha256', 'pulse-memory:v1|' . $sourceKey . '|' . (string) ($context['pulse_identity_id'] ?? '')), 0, 8)) % 4 !== 0) { return null; }
        $prior = $memory[0] ?? null;
        if (!is_array($prior) || (string) ($prior['post_text'] ?? '') === '') { return null; }
        $stance = (string) ($prior['stance'] ?? 'neutral');
        $excerpt = $this->excerpt((string) $prior['post_text']);
        $text = match ($stance) {
            'criticism' => 'I was harsher before this one. Fair play, {player} answered.',
            'praise' => 'I said "{memory_excerpt}" before this one. Still backing it.',
            default => 'I remember writing "{memory_excerpt}". The conversation has moved on.',
        };

        return ['voice' => (string) ($context['pulse_voice'] ?? 'neutral_viewer'), 'family' => 'memory|' . $stance, 'opening' => 'memory_callback', 'slang' => '', 'emoji' => '', 'actors' => ['fan', 'media', 'club', 'national', 'rival', 'competition', 'teammate'], 'results' => [], 'text' => str_replace('{memory_excerpt}', $excerpt, $text), 'stance' => 'neutral', 'reference' => 'EVIDENCE_BACKED_MEMORY'];
    }

    /** @param array<string, mixed> $context @param array<string, array<string, bool>> $used */
    private function selectThreadReaction(string $intent, string $actorType, array $context, string $sourceKey, string $actorId, array $recent, array $used): array
    {
        $candidates = array_values(array_filter(self::THREAD_CATALOG[$intent] ?? [], static fn (array $candidate): bool => in_array($actorType, $candidate['actors'], true)));
        $culture = $this->cultureForNation((string) ($context['pulse_culture'] ?? ''));
        if (isset(self::CULTURE_THREAD_REPLIES[$culture])) {
            $cultureIntent = $intent === 'reluctant_agreement' || $intent === 'defend_player' ? 'agree' : ($intent === 'callback' ? 'disagree' : $intent);
            $cultureCandidate = self::CULTURE_THREAD_REPLIES[$culture][$cultureIntent] ?? null;
            $nativeAllowed = hexdec(substr(hash('sha256', 'pulse-thread-native:v1|' . $sourceKey . '|' . $culture . '|' . $intent), 0, 8)) % 4 === 0;
            if (is_array($cultureCandidate) && (($cultureCandidate['native'] ?? false) === false || $nativeAllowed)) {
                array_unshift($candidates, ['voice' => (string) ($context['pulse_voice'] ?? 'neutral_viewer'), 'family' => 'culture_thread|' . $culture . '|' . $cultureCandidate['family'], 'opening' => $cultureCandidate['opening'], 'slang' => $cultureCandidate['slang'], 'emoji' => $cultureCandidate['emoji'], 'actors' => [$actorType], 'text' => $cultureCandidate['text']]);
            }
        }
        $preferredVoice = (string) ($context['pulse_voice'] ?? '');
        if ($preferredVoice !== '') {
            $voiceCandidates = array_values(array_filter($candidates, static fn (array $candidate): bool => (string) ($candidate['voice'] ?? '') === $preferredVoice));
            if ($voiceCandidates !== []) { $candidates = $voiceCandidates; }
        }
        if ($candidates === []) {
            $fallback = match ($intent) { 'agree' => 'Exactly.', 'disagree' => 'I do not see it that way.', 'reluctant_agreement' => 'Fair enough.', 'defend_player' => 'Let the Player have the moment.', 'rival_banter' => 'We will see about that.', 'callback' => 'And now the conversation has changed.', default => 'Football opinions move quickly.' };
            return ['text' => $fallback, 'meta' => ['voice' => self::VOICES_BY_ACTOR[$actorType][0] ?? 'neutral_viewer', 'family' => 'thread_fallback|' . $intent, 'opening' => 'fallback', 'slang' => '', 'emoji' => '']];
        }
        $start = hexdec(substr(hash('sha256', 'pulse-thread-reaction:v1|' . $sourceKey . '|' . $intent . '|' . $actorType . '|' . $actorId), 0, 8)) % count($candidates);
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($candidates as $offset => $candidate) {
            $candidate = $candidates[($start + $offset) % count($candidates)];
            $text = $this->interpolate($candidate['text'], $context);
            $score = 0;
            if (isset($recent['texts'][$text]) || isset($used['texts'][$text])) { $score += 1000; }
            if (isset($recent['families'][$candidate['family']]) || isset($used['families'][$candidate['family']])) { $score += 100; }
            if (isset($recent['openings'][$candidate['opening']]) || isset($used['openings'][$candidate['opening']])) { $score += 30; }
            if ($candidate['slang'] !== '' && (isset($recent['slang'][$candidate['slang']]) || isset($used['slang'][$candidate['slang']]))) { $score += 20; }
            if ($candidate['emoji'] !== '' && (isset($recent['emojis'][$candidate['emoji']]) || isset($used['emojis'][$candidate['emoji']]))) { $score += 15; }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = ['text' => $text, 'meta' => ['voice' => $candidate['voice'], 'family' => $candidate['family'], 'opening' => $candidate['opening'], 'slang' => $candidate['slang'], 'emoji' => $candidate['emoji']]];
                if ($score === 0) { break; }
            }
        }

        return $best ?? ['text' => 'Football opinions move quickly.', 'meta' => ['voice' => 'neutral_viewer', 'family' => 'thread_fallback', 'opening' => 'fallback', 'slang' => '', 'emoji' => '']];
    }

    /** @return array<string, bool> */
    private function recentThreadPatterns(DatabaseInterface $database, string $playerId): array
    {
        $patterns = [];
        if ($this->available($database, self::THREAD_EDGES)) {
            $statement = $database->connection()->prepare('SELECT e.pattern_family FROM ' . self::THREAD_EDGES . ' e JOIN ' . self::POSTS . ' p ON p.id = e.post_id WHERE e.player_id = :player_id ORDER BY p.occurred_date DESC, p.id DESC LIMIT 12');
            $statement->execute(['player_id' => $playerId]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $pattern) { if ((string) $pattern !== '') { $patterns[(string) $pattern] = true; } }
        }
        $statement = $database->connection()->prepare('SELECT context_json FROM ' . self::SOURCES . ' WHERE player_id = :player_id ORDER BY occurred_date DESC, source_key DESC LIMIT 12');
        $statement->execute(['player_id' => $playerId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $context = json_decode((string) $json, true);
            $pattern = is_array($context) && is_array($context['thread_meta'] ?? null) ? (string) ($context['thread_meta']['pattern'] ?? '') : '';
            if ($pattern !== '') { $patterns[$pattern] = true; }
        }

        return $patterns;
    }

    /** @return array{texts:array<string,bool>,families:array<string,bool>,openings:array<string,bool>,slang:array<string,bool>,emojis:array<string,bool>} */
    private function recentThreadReactionHistory(DatabaseInterface $database, string $playerId): array
    {
        $history = ['texts' => [], 'families' => [], 'openings' => [], 'slang' => [], 'emojis' => []];
        if (!$this->available($database, self::THREAD_EDGES)) { return $history; }
        $statement = $database->connection()->prepare('SELECT p.post_text, e.pattern_family, e.intent, e.voice FROM ' . self::POSTS . ' p JOIN ' . self::THREAD_EDGES . ' e ON e.post_id = p.id WHERE p.player_id = :player_id ORDER BY p.occurred_date DESC, p.id DESC LIMIT 24');
        $statement->execute(['player_id' => $playerId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $text = (string) $row['post_text'];
            $history['texts'][$text] = true;
            $history['families'][(string) $row['pattern_family'] . '|' . (string) $row['intent']] = true;
            $history['families'][(string) $row['pattern_family']] = true;
        }

        return $history;
    }

    private function insertThreadEdge(DatabaseInterface $database, string $postId, string $playerId, string $sourceKey, ?string $parentId, ?string $quoteId, string $rootId, int $depth, string $intent, string $pattern, string $voice): void
    {
        $statement = $database->connection()->prepare('INSERT OR IGNORE INTO ' . self::THREAD_EDGES . ' (post_id, player_id, source_key, parent_post_id, quote_post_id, thread_root_id, depth, intent, pattern_family, voice) VALUES (:post_id, :player_id, :source_key, :parent, :quote, :root, :depth, :intent, :pattern, :voice)');
        $statement->execute(['post_id' => $postId, 'player_id' => $playerId, 'source_key' => $sourceKey, 'parent' => $parentId, 'quote' => $quoteId, 'root' => $rootId, 'depth' => max(1, min(self::MAX_THREAD_DEPTH, $depth)), 'intent' => $intent, 'pattern' => $pattern, 'voice' => $voice]);
    }

    private function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 42 ? mb_substr($text, 0, 39) . '…' : $text;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, array<string, bool>> $used
     * @return array{text:string,meta:array{voice:string,family:string,opening:string,slang:string,emoji:string,text:string}}
     */
    private function selectReaction(string $kind, string $actorType, array $context, string $sourceKey, string $actorId, array $recent, array $used): array
    {
        $candidates = $this->cultureCandidates($kind, $actorType, $context, $sourceKey);
        $memoryCandidate = $this->memoryCandidate($kind, $context, $sourceKey);
        if ($memoryCandidate !== null) { array_unshift($candidates, $memoryCandidate); }
        $result = (string) ($context['result'] ?? '');
        foreach (self::REACTION_CATALOG[$kind] ?? [] as $candidate) {
            if (!in_array($actorType, $candidate['actors'], true)) { continue; }
            if ($candidate['results'] !== [] && !in_array($result, $candidate['results'], true)) { continue; }
            $candidates[] = $candidate;
        }
        $preferredVoice = (string) ($context['pulse_voice'] ?? '');
        if ($preferredVoice !== '') {
            $voiceCandidates = array_values(array_filter($candidates, static fn (array $candidate): bool => (string) ($candidate['voice'] ?? '') === $preferredVoice));
            if ($voiceCandidates !== []) { $candidates = $voiceCandidates; }
        }
        if ($candidates === []) {
            $text = $this->render($kind, $actorType, $context, $sourceKey);
            return ['text' => $text, 'meta' => ['voice' => self::VOICES_BY_ACTOR[$actorType][0] ?? 'neutral_viewer', 'family' => 'legacy|' . $kind, 'opening' => 'legacy', 'slang' => '', 'emoji' => '', 'stance' => 'neutral', 'text' => $text]];
        }

        $start = hexdec(substr(hash('sha256', 'pulse-reaction:v1|' . $sourceKey . '|' . $actorType . '|' . $actorId . '|' . $kind), 0, 8)) % count($candidates);
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($candidates as $offset => $candidate) {
            $candidate = $candidates[($start + $offset) % count($candidates)];
            $text = $this->interpolate($candidate['text'], $context);
            $family = $candidate['family'];
            $opening = $candidate['opening'];
            $slang = $candidate['slang'];
            $emoji = $candidate['emoji'];
            $score = 0;
            if (isset($recent['texts'][$text]) || isset($used['texts'][$text])) { $score += 1000; }
            if (isset($recent['families'][$family]) || isset($used['families'][$family])) { $score += 100; }
            if (isset($recent['openings'][$opening]) || isset($used['openings'][$opening])) { $score += 30; }
            if ($slang !== '' && (isset($recent['slang'][$slang]) || isset($used['slang'][$slang]))) { $score += 20; }
            if ($emoji !== '' && (isset($recent['emojis'][$emoji]) || isset($used['emojis'][$emoji]))) { $score += 15; }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = ['text' => $text, 'meta' => ['voice' => $candidate['voice'], 'family' => $family, 'opening' => $opening, 'slang' => $slang, 'emoji' => $emoji, 'stance' => (string) ($candidate['stance'] ?? $this->reactionStance($kind, $context, $actorType)), 'reference' => (string) ($candidate['reference'] ?? ''), 'text' => $text]];
                if ($score === 0) { break; }
            }
        }

        if ($best !== null && $bestScore >= 1000) {
            $legacyText = $this->render($kind, $actorType, $context, $sourceKey);
            if (!isset($recent['texts'][$legacyText]) && !isset($used['texts'][$legacyText])) {
                return ['text' => $legacyText, 'meta' => ['voice' => $preferredVoice !== '' ? $preferredVoice : (self::VOICES_BY_ACTOR[$actorType][0] ?? 'neutral_viewer'), 'family' => 'legacy|' . $kind, 'opening' => 'legacy', 'slang' => '', 'emoji' => '', 'stance' => $this->reactionStance($kind, $context, $actorType), 'text' => $legacyText]];
            }
        }

        return $best ?? ['text' => $this->render($kind, $actorType, $context, $sourceKey), 'meta' => ['voice' => $preferredVoice !== '' ? $preferredVoice : (self::VOICES_BY_ACTOR[$actorType][0] ?? 'neutral_viewer'), 'family' => 'legacy|' . $kind, 'opening' => 'legacy', 'slang' => '', 'emoji' => '', 'stance' => $this->reactionStance($kind, $context, $actorType), 'text' => '']];
    }

    private function reactionStance(string $kind, array $context, string $actorType): string
    {
        if ($actorType === 'rival' || in_array($kind, ['match_red_card', 'injury'], true) || (string) ($context['result'] ?? '') === 'loss') { return 'criticism'; }
        if (in_array($kind, ['match_goal', 'match_assist', 'match_decisive_goal', 'match_major_contribution', 'match_strong_performance', 'award', 'honour', 'record', 'milestone', 'return'], true) || (string) ($context['result'] ?? '') === 'win') { return 'praise'; }

        return 'neutral';
    }

    /** @return array{texts:array<string,bool>,families:array<string,bool>,openings:array<string,bool>,slang:array<string,bool>,emojis:array<string,bool>} */
    private function recentReactionHistory(DatabaseInterface $database, string $playerId): array
    {
        $history = ['texts' => [], 'families' => [], 'openings' => [], 'slang' => [], 'emojis' => []];
        if (!$this->available($database, self::POSTS) || !$this->available($database, self::SOURCES)) { return $history; }
        $statement = $database->connection()->prepare('SELECT p.post_text, p.actor_type, p.actor_id, s.context_json FROM ' . self::POSTS . ' p JOIN ' . self::SOURCES . ' s ON s.source_key = p.source_key WHERE p.player_id = :player_id ORDER BY p.occurred_date DESC, p.id DESC LIMIT 24');
        $statement->execute(['player_id' => $playerId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $text = (string) ($row['post_text'] ?? '');
            if ($text !== '') { $history['texts'][$text] = true; }
            $context = json_decode((string) ($row['context_json'] ?? ''), true);
            $key = (string) ($row['actor_type'] ?? 'fan') . '|' . (string) ($row['actor_id'] ?? 'unknown');
            $meta = is_array($context) && is_array($context['reaction_meta'] ?? null) && is_array($context['reaction_meta'][$key] ?? null) ? $context['reaction_meta'][$key] : [];
            foreach (['family' => 'families', 'opening' => 'openings', 'slang' => 'slang', 'emoji' => 'emojis'] as $metaKey => $historyKey) {
                $value = (string) ($meta[$metaKey] ?? '');
                if ($value !== '') { $history[$historyKey][$value] = true; }
            }
        }

        return $history;
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $template, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) { $replacements['{' . $key . '}'] = (string) $value; }
        }

        return strtr($template, $replacements);
    }

    /** @return list<array{id:string,label:string,text:string}> */
    private function responseChoices(string $context): array
    {
        return match ($context) {
            'defeat' => [['id' => 'back_team', 'label' => 'Back the team', 'text' => 'We take the lesson together and keep moving.'], ['id' => 'responsibility', 'label' => 'Take responsibility', 'text' => 'I take responsibility and will work to be better next time.'], ['id' => 'silence', 'label' => 'Stay quiet', 'text' => '']],
            'red_card' => [['id' => 'responsibility', 'label' => 'Accept responsibility', 'text' => 'I accept responsibility for the dismissal.'], ['id' => 'back_team', 'label' => 'Back the team', 'text' => 'The team gave everything; we will respond together.'], ['id' => 'silence', 'label' => 'Say nothing', 'text' => '']],
            'transfer' => [['id' => 'thank_former_club', 'label' => 'Thank the former Club', 'text' => 'Thank you to everyone at my former Club for the memories.'], ['id' => 'next_chapter', 'label' => 'Embrace the next chapter', 'text' => 'Excited for the next chapter and ready to work.'], ['id' => 'professional', 'label' => 'Keep it professional', 'text' => 'Focused on football and the work ahead.'], ['id' => 'silence', 'label' => 'Say nothing', 'text' => '']],
            'retirement' => [['id' => 'thank_supporters', 'label' => 'Thank supporters', 'text' => 'Thank you to everyone who shared this playing Career with me.'], ['id' => 'thank_clubs', 'label' => 'Thank Clubs and teammates', 'text' => 'Thank you to every Club, teammate, and coach who shaped this journey.'], ['id' => 'reflect', 'label' => 'Reflect on the Career', 'text' => 'I will always be proud of the work, the people, and the football.'], ['id' => 'silence', 'label' => 'Keep it brief', 'text' => '']],
            default => [['id' => 'team_first', 'label' => 'Celebrate the team', 'text' => 'Proud of the team tonight. Thank you for the support.'], ['id' => 'supporter_first', 'label' => 'Thank supporters', 'text' => 'Thank you to the supporters for staying with us.'], ['id' => 'professional', 'label' => 'Stay focused', 'text' => 'Enjoy the moment, then back to work.'], ['id' => 'silence', 'label' => 'Say nothing', 'text' => '']],
        };
    }

    private function engagement(DatabaseInterface $database, string $playerId, string $importance, string $actorType): int
    {
        $base = 80 + ($this->audienceScore($database, $playerId) * 35) + match ($importance) { 'landmark' => 900, 'major' => 420, 'notable' => 150, default => 40 };
        $multiplier = match ($actorType) { 'media', 'competition', 'national' => 2, 'club' => 3, 'player' => 1, default => 1 };

        return $base * $multiplier + (hexdec(substr(hash('sha256', 'pulse-engagement:v1|' . $playerId . '|' . $importance . '|' . $actorType), 0, 4)) % 90);
    }

    private function audienceScore(DatabaseInterface $database, string $playerId): int
    {
        if (!$this->available($database, self::AUDIENCE)) { return 0; }
        $statement = $database->connection()->prepare('SELECT audience_score FROM ' . self::AUDIENCE . ' WHERE player_id = :player_id'); $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();

        return $value === false ? $this->fallbackAudienceScore($database, $playerId) : (int) $value;
    }

    private function writeAudience(DatabaseInterface $database, string $playerId, int $score, SimulationDate $date): void
    {
        $statement = $database->connection()->prepare('INSERT INTO ' . self::AUDIENCE . ' (player_id, audience_score, updated_date) VALUES (:player_id, :score, :date) ON CONFLICT(player_id) DO UPDATE SET audience_score = excluded.audience_score, updated_date = excluded.updated_date');
        $statement->execute(['player_id' => $playerId, 'score' => max(0, min(100, $score)), 'date' => $date->toIsoString()]);
    }

    private function nextAudience(int $score, string $importance, string $kind): int
    {
        $delta = match ($importance) { 'landmark' => 7, 'major' => 4, 'notable' => 2, default => 0 };
        if ($kind === 'injury') { return $score; }
        if (in_array($kind, ['match_goal', 'match_decisive_goal', 'award', 'honour', 'record'], true)) { ++$delta; }

        return min(100, $score + $delta);
    }

    private function fallbackAudienceScore(DatabaseInterface $database, string $playerId): int
    {
        if (!$this->available($database, 'player_social_states')) { return 0; }
        $statement = $database->connection()->prepare('SELECT public_profile FROM player_social_states WHERE player_id = :player_id'); $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();

        return $value === false ? 0 : max(0, min(100, intdiv((int) $value, 2)));
    }

    private function currentClub(DatabaseInterface $database, string $playerId): ?string
    {
        if ($this->available($database, 'player_social_states')) {
            $state = $database->connection()->prepare('SELECT current_club_id FROM player_social_states WHERE player_id = :player_id');
            $state->execute(['player_id' => $playerId]);
            $current = $state->fetchColumn();
            if ($current === false || $current === null || (string) $current === '') { return null; }

            return (string) $current;
        }
        if (!$this->available($database, 'club_squad_memberships')) { return null; }
        $statement = $database->connection()->prepare('SELECT club_id FROM club_squad_memberships WHERE player_id = :player_id ORDER BY season_id DESC LIMIT 1');
        $statement->execute(['player_id' => $playerId]);
        $value = $statement->fetchColumn();

        return $value === false || (string) $value === '' ? null : (string) $value;
    }

    private function audienceBand(int $score): string { return match (true) { $score < 15 => 'Local Following', $score < 35 => 'Growing Audience', $score < 60 => 'National Attention', $score < 82 => 'International Following', default => 'Global Star' }; }
    private function followers(int $score): int { return match (true) { $score < 15 => 120 + ($score * 35), $score < 35 => 700 + (($score - 15) * 140), $score < 60 => 3500 + (($score - 35) * 600), $score < 82 => 20000 + (($score - 60) * 1800), default => 65000 + (($score - 82) * 7000) }; }
    private function followersLabel(int $followers): string { return $followers >= 1000000 ? number_format($followers / 1000000, 1) . 'M' : ($followers >= 1000 ? number_format($followers / 1000, 1) . 'K' : number_format($followers)); }
    private function prune(DatabaseInterface $database, string $playerId): void
    {
        $old = $database->connection()->prepare('SELECT source_key FROM ' . self::SOURCES . ' WHERE player_id = :player_id ORDER BY occurred_date DESC, source_key DESC LIMIT -1 OFFSET ' . self::MAX_SOURCES); $old->execute(['player_id' => $playerId]); $keys = $old->fetchAll(PDO::FETCH_COLUMN);
        foreach ($keys as $key) {
            if ($this->available($database, self::THREAD_EDGES)) { $database->connection()->prepare('DELETE FROM ' . self::THREAD_EDGES . ' WHERE source_key = :source_key')->execute(['source_key' => $key]); }
            $database->connection()->prepare('DELETE FROM ' . self::POSTS . ' WHERE source_key = :source_key')->execute(['source_key' => $key]);
            $database->connection()->prepare('DELETE FROM ' . self::RESPONSES . ' WHERE source_key = :source_key')->execute(['source_key' => $key]);
            $database->connection()->prepare('DELETE FROM ' . self::SOURCES . ' WHERE source_key = :source_key')->execute(['source_key' => $key]);
        }
    }

    /** @return array{type:string,id:string,name:string}|null */
    private function relatedPlayer(DatabaseInterface $database, GameMatch $match, PlayerMatchStat $stat, string $kind): ?array
    {
        if (!in_array($kind, ['match_goal', 'match_assist', 'match_decisive_goal', 'match_major_contribution'], true)) { return null; }
        foreach ((new MatchHighlightRepository($database))->byMatch($match->id()) as $highlight) {
            $data = $highlight->data();
            $candidate = $highlight->playerId()?->value();
            if ($candidate === $stat->playerId()->value() && isset($data['assist_player_id'])) { $candidate = (string) $data['assist_player_id']; }
            if ($candidate === null || $candidate === $stat->playerId()->value()) { continue; }
            $sameClub = $highlight->clubId()?->value() === $stat->clubId()->value();
            if ($sameClub) { return ['type' => 'teammate', 'id' => $candidate, 'name' => $this->playerName($database, $candidate)]; }
            if ($kind === 'match_decisive_goal' || $kind === 'match_major_contribution') { return ['type' => 'rival', 'id' => $candidate, 'name' => $this->playerName($database, $candidate)]; }
        }

        return null;
    }

    private function playerName(DatabaseInterface $database, string $playerId): string
    {
        $statement = $database->connection()->prepare('SELECT preferred_name, first_name, last_name FROM player_records WHERE id = :id'); $statement->execute(['id' => $playerId]); $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? trim((string) (($row['preferred_name'] ?? '') ?: (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')))) : 'Player';
    }

    private function clubName(DatabaseInterface $database, string $clubId): string
    {
        $statement = $database->connection()->prepare('SELECT canonical_name FROM club_records WHERE id = :id'); $statement->execute(['id' => $clubId]); $value = $statement->fetchColumn();

        return $value === false ? $clubId : (string) $value;
    }

    private function clubCountry(DatabaseInterface $database, ?string $clubId): ?string
    {
        if ($clubId === null || $clubId === '' || !$this->available($database, 'club_records')) { return null; }
        $statement = $database->connection()->prepare('SELECT nation_id FROM club_records WHERE id = :id'); $statement->execute(['id' => $clubId]); $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function competitionCountry(DatabaseInterface $database, ?string $competitionId): ?string
    {
        if ($competitionId === null || $competitionId === '' || !$this->available($database, 'competition_records')) { return null; }
        $statement = $database->connection()->prepare('SELECT nation_id FROM competition_records WHERE id = :id'); $statement->execute(['id' => $competitionId]); $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function playerNationality(DatabaseInterface $database, string $playerId): ?string
    {
        if (!$this->available($database, 'player_records')) { return null; }
        $statement = $database->connection()->prepare('SELECT primary_nation_id FROM player_records WHERE id = :id'); $statement->execute(['id' => $playerId]); $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function competitionName(DatabaseInterface $database, string $competitionId): string
    {
        $statement = $database->connection()->prepare('SELECT name FROM competition_records WHERE id = :id'); $statement->execute(['id' => $competitionId]); $value = $statement->fetchColumn();

        return $value === false ? 'competition' : (string) $value;
    }

    private function competitionType(DatabaseInterface $database, string $competitionId): string
    {
        $statement = $database->connection()->prepare('SELECT type FROM competition_records WHERE id = :id'); $statement->execute(['id' => $competitionId]); $value = $statement->fetchColumn();

        return $value === false ? 'domestic_league' : (string) $value;
    }

    private function available(DatabaseInterface $database, string $table): bool
    {
        $statement = $database->connection()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table"); $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function postId(string $id): string { return hash('sha256', $id); }
    private function id(PlayerId|string $id): string { return $id instanceof PlayerId ? $id->value() : $id; }
}
