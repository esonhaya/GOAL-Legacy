<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player;

/**
 * Declarative content for the small between-match career layer.
 *
 * The catalog contains situations, not football outcomes. Choices may route
 * through the existing training/priority owners and may leave a small memory
 * flag for a later callback. The CareerExperienceService owns eligibility,
 * selection, persistence, and consequence application.
 */
final class CareerEventCatalog
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::event('extra-technical-work', 'training', 'A focused training opportunity', 'The staff at {club} offer extra individual work before the next fixture.', [
                self::choice('focus-passing', 'Use the time to sharpen passing', 'Training focus changed to Passing', null, 'passing', 'development'),
                self::choice('focus-shooting', 'Use the time to sharpen finishing', 'Training focus changed to Shooting', null, 'shooting', 'development'),
                self::choice('keep-balance', 'Keep a balanced programme', 'Kept a balanced training programme', null, 'balanced', 'balanced'),
            ], ['priority_categories' => ['development', 'professional', 'balanced'], 'newsworthy' => false, 'historyworthy' => false]),
            self::event('recovery-window', 'recovery', 'A chance to reset', "A quieter week opens up around {club}'s schedule.", [
                self::choice('protect-recovery', 'Put recovery first', 'Prioritised recovery during a quiet week', null, null, 'recovery'),
                self::choice('keep-working', 'Keep the normal professional routine', 'Kept the normal professional routine', null, null, 'professional'),
                self::choice('balanced-week', 'Keep the week balanced', 'Kept the week balanced', null, null, 'balanced'),
            ], ['priority_categories' => ['recovery', 'lifestyle', 'balanced'], 'newsworthy' => false, 'historyworthy' => false]),
            self::event('coach-review', 'team', 'A word with the coaching staff', 'The staff at {club} want to discuss how you can help the team.', [
                self::choice('team-first', 'Focus on your role in the team', 'Focused on the team role after a staff review', null, null, 'professional'),
                self::choice('development-plan', 'Ask for a development plan', 'Asked the staff for a development plan', null, null, 'development'),
                self::choice('balanced-review', 'Keep the current balance', 'Kept the current balance after a staff review', null, null, 'balanced'),
            ], ['priority_categories' => ['professional', 'development', 'balanced'], 'newsworthy' => true]),
            self::event('family-weekend', 'family', 'A family request', 'Your family would value time together during the next break.', [
                self::choice('make-time', 'Make time for family', 'Made time for family during a break', null, null, 'lifestyle'),
                self::choice('short-visit', 'Arrange a short visit and keep the routine', 'Balanced family time with the football routine', null, null, 'balanced'),
                self::choice('stay-focused', 'Stay with the football routine', 'Kept the football routine during a family break', null, null, 'professional'),
            ], ['priority_categories' => ['lifestyle', 'recovery', 'balanced'], 'newsworthy' => false, 'historyworthy' => false]),
            self::event('teammates-invite', 'social', 'Teammates invite you out', 'A few teammates from {club} invite you to spend an evening together.', [
                self::choice('join-team', 'Join them for the evening', 'Spent time with teammates away from football', null, null, 'lifestyle'),
                self::choice('leave-early', 'Join them, then leave early', 'Joined teammates briefly before returning to routine', null, null, 'balanced'),
                self::choice('recover-instead', 'Skip it and recover', 'Skipped a social evening to recover', null, null, 'recovery'),
            ], ['priority_categories' => ['lifestyle', 'recovery', 'balanced'], 'newsworthy' => false, 'historyworthy' => false]),
            self::event('personal-routine', 'lifestyle', 'A little time for yourself', 'The next few days offer a rare chance to choose how you want to spend your time away from {club}.', [
                self::choice('enjoy-the-break', 'Enjoy the break', 'Made room for life away from football', null, null, 'lifestyle'),
                self::choice('keep-structure', 'Keep a structured routine', 'Kept a structured routine away from the Club', null, null, 'professional'),
                self::choice('rest-quietly', 'Use the time to rest quietly', 'Used a quiet break to recover', null, null, 'recovery'),
            ], ['priority_categories' => ['lifestyle', 'recovery', 'balanced'], 'newsworthy' => false, 'historyworthy' => false]),
            self::event('community-visit', 'community', 'A community invitation', 'A local community group asks for a short appearance from a {club} player.', [
                self::choice('represent-club', 'Represent the Club', 'Represented the Club at a community event', null, null, 'professional', 'community_engagement'),
                self::choice('short-appearance', 'Make a short appearance', 'Made a short community appearance', null, null, 'balanced'),
                self::choice('protect-time', 'Protect the training schedule', 'Protected the training schedule instead of attending', null, null, 'development'),
            ], ['priority_categories' => ['professional', 'lifestyle', 'development'], 'newsworthy' => true]),
            self::event('media-request', 'media', 'A media request', 'A local outlet wants to hear about your progress at {club}.', [
                self::choice('speak-proudly', 'Speak about the Club with pride', 'Spoke publicly about the Club', null, null, 'professional', 'media_composure'),
                self::choice('brief-answer', 'Give a brief answer and move on', 'Gave a brief media answer', null, null, 'balanced'),
                self::choice('decline-media', 'Decline and keep working', 'Declined a media request to keep working', null, null, 'development', 'media_reserved'),
            ], ['priority_categories' => ['professional', 'development', 'lifestyle'], 'newsworthy' => true]),
            self::event('new-city-adjustment', 'adaptation', 'Settling into the new routine', 'The demands of a new football environment are beginning to feel real.', [
                self::choice('ask-for-help', 'Ask teammates for help settling in', 'Asked teammates for help settling into the Club', null, null, 'lifestyle', 'settling_in_started'),
                self::choice('stay-professional', 'Keep the professional routine', 'Kept a professional routine while settling in', null, null, 'professional'),
                self::choice('take-it-slow', 'Take the adjustment slowly', 'Took time to adjust to a new routine', null, null, 'recovery', 'settling_in_started'),
            ], ['priority_categories' => ['lifestyle', 'professional', 'recovery'], 'newsworthy' => false, 'historyworthy' => false]),
            self::event('form-conversation', 'career', 'A conversation about your form', 'The people around {club} want to talk through your next step.', [
                self::choice('push-forward', 'Push for a bigger football role', 'Asked to push forward in the football role', null, null, 'professional'),
                self::choice('focus-development', 'Focus on steady development', 'Chose steady development as the next step', null, null, 'development'),
                self::choice('protect-balance', 'Protect a balanced routine', 'Protected a balanced routine while considering the next step', null, null, 'balanced'),
            ], ['priority_categories' => ['professional', 'development', 'balanced'], 'newsworthy' => true]),

            self::event('career-first-appearance', 'career', 'Your first senior appearance', 'You have finally stepped onto the senior stage for {club}. The next choice is how to build from it.', [
                self::choice('learn-from-it', 'Review the experience carefully', 'Reviewed the first senior appearance with the staff', null, null, 'development', 'first_appearance_reflection'),
                self::choice('enjoy-the-moment', 'Enjoy the moment with your family', 'Shared the first senior appearance with family', null, null, 'lifestyle', 'first_appearance_reflection'),
            ], ['requires' => ['appearances_min' => 1, 'history_absent' => 'first_appearance_reflection'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 12]),
            self::event('career-first-start', 'career', 'A first start to remember', 'The first time you were named in the starting eleven for {club} has changed the feeling around the week.', [
                self::choice('prepare-again', 'Study what earned the start', 'Studied the preparation that led to a first start', null, null, 'development', 'first_start_reflection'),
                self::choice('take-responsibility', 'Embrace the responsibility', 'Embraced the responsibility of a first start', null, null, 'professional', 'first_start_reflection'),
            ], ['requires' => ['starts_min' => 1, 'history_absent' => 'first_start_reflection'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 13]),
            self::event('career-first-goal', 'career', 'A first goal changes the conversation', 'Your first senior goal has given {club} and the people around you something to celebrate.', [
                self::choice('stay-grounded', 'Stay grounded and keep working', 'Stayed grounded after a first senior goal', null, null, 'professional', 'first_goal_reflection'),
                self::choice('share-celebration', 'Share the celebration with supporters', 'Shared the first senior goal with supporters', null, null, 'lifestyle', 'first_goal_reflection'),
            ], ['requires' => ['goals_min' => 1, 'recent_goals_min' => 1, 'history_absent' => 'first_goal_reflection'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 15]),
            self::event('form-breakout-attention', 'form', 'A run of form draws attention', 'Your recent performances for {club} are starting to change what people expect from you.', [
                self::choice('keep-routine', 'Keep the routine that is working', 'Kept the routine behind a strong run of form', null, null, 'professional', 'breakout_attention'),
                self::choice('welcome-the-moment', 'Welcome the attention carefully', 'Handled new attention after a strong run of form', null, null, 'balanced', 'breakout_attention'),
            ], ['requires' => ['forms' => ['excellent', 'good'], 'history_absent' => 'breakout_attention'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 11]),
            self::event('form-poor-run', 'form', 'A difficult run needs a response', 'A run of difficult results has made the next few weeks at {club} important.', [
                self::choice('return-to-basics', 'Return to the basics', 'Returned to the basics after a difficult run', null, null, 'development', 'poor_form_response'),
                self::choice('protect-recovery', 'Protect recovery and confidence', 'Protected recovery during a difficult run', null, null, 'recovery', 'poor_form_response'),
                self::choice('ask-for-feedback', 'Ask the staff for honest feedback', 'Asked the staff for honest feedback after a difficult run', null, null, 'professional', 'poor_form_response'),
            ], ['requires' => ['forms' => ['poor', 'very_poor'], 'history_absent' => 'poor_form_response'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 10]),
            self::event('manager-form-praise', 'manager', 'The manager notices your progress', 'The manager has noticed the quality of your recent work at {club}.', [
                self::choice('ask-for-more', 'Ask for the next challenge', 'Asked the manager for the next challenge', null, null, 'development', 'manager_feedback'),
                self::choice('serve-the-team', 'Keep serving the team', 'Kept serving the team after receiving praise', null, null, 'professional', 'manager_feedback'),
            ], ['requires' => ['forms' => ['excellent', 'good'], 'history_absent' => 'manager_feedback'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 8]),
            self::event('manager-patience', 'manager', 'A patient conversation', 'The manager wants you to understand what the next step looks like while you compete for minutes at {club}.', [
                self::choice('trust-the-process', 'Trust the process', 'Accepted the manager\'s patience plan', null, null, 'professional', 'manager_patience'),
                self::choice('request-a-plan', 'Ask for clear development targets', 'Asked for clear development targets from the manager', null, null, 'development', 'manager_patience'),
            ], ['requires' => ['roles' => ['prospect', 'rotation'], 'history_absent' => 'manager_patience'], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'context_weight' => 9]),
            self::event('manager-role-briefing', 'manager', 'Your role is changing', 'The manager explains what {club} needs from you in the coming stretch of the Season.', [
                self::choice('accept-role', 'Accept the role and prepare', 'Accepted the manager\'s role expectations', null, null, 'professional', 'role_briefing'),
                self::choice('ask-to-develop', 'Ask where you can improve', 'Asked where to improve before taking on a new role', null, null, 'development', 'role_briefing'),
            ], ['requires' => ['roles' => ['regular', 'key_player'], 'history_absent' => 'role_briefing'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 8]),
            self::event('training-weakness-session', 'training', 'A weakness to work on', 'The staff have identified one part of your game that could make a difference for {club}.', [
                self::choice('focus-passing', 'Make Passing the next focus', 'Made Passing the next training focus', null, 'passing', 'development', 'weakness_session'),
                self::choice('focus-defending', 'Make Defending the next focus', 'Made Defending the next training focus', null, 'defending', 'development', 'weakness_session'),
                self::choice('keep-current-focus', 'Keep the current focus', 'Kept the current training focus after staff feedback', null, null, 'balanced', 'weakness_session'),
            ], ['requires' => ['history_absent' => 'weakness_session'], 'priority_categories' => ['development', 'professional'], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'context_weight' => 7]),
            self::event('training-senior-advice', 'training', 'A senior teammate offers advice', 'A senior player at {club} offers to share the details that helped them improve.', [
                self::choice('take-advice', 'Listen and apply it', 'Accepted a senior teammate\'s advice', null, null, 'development', 'mentor_started'),
                self::choice('keep-own-method', 'Thank them and keep your method', 'Kept your own method after a senior teammate\'s advice', null, null, 'balanced', 'mentor_declined'),
            ], ['requires' => ['history_absent' => 'mentor_started', 'history_absent_any' => ['mentor_declined']], 'priority_categories' => ['development', 'professional'], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'chain_id' => 'mentorship', 'chain_stage' => 1, 'context_weight' => 9]),
            self::event('training-rival-challenge', 'training', 'A position rival raises the level', 'A teammate competing for your position has challenged you to raise the standard at {club}.', [
                self::choice('compete-constructively', 'Compete in training', 'Competed constructively with a position rival', null, null, 'development', 'position_challenge'),
                self::choice('support-the-rival', 'Share ideas and learn together', 'Shared ideas with a position rival', null, null, 'professional', 'position_challenge'),
                self::choice('protect-recovery', 'Keep your preparation measured', 'Kept preparation measured during a position challenge', null, null, 'recovery', 'position_challenge'),
            ], ['requires' => ['position_competition' => true, 'history_absent' => 'position_challenge'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'chain_id' => 'position-competition', 'chain_stage' => 1, 'context_weight' => 10]),
            self::event('training-rival-followup', 'training', 'The challenge becomes a lesson', 'The position challenge has shown you something useful about competing for a place at {club}.', [
                self::choice('apply-the-lesson', 'Apply the lesson in training', 'Applied a lesson from a position challenge', null, null, 'development', 'position_resolution'),
                self::choice('keep-the-perspective', 'Keep the competition in perspective', 'Kept a position competition in perspective', null, null, 'balanced', 'position_resolution'),
            ], ['requires' => ['history_present' => 'position_challenge', 'history_absent' => 'position_resolution'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'chain_id' => 'position-competition', 'chain_stage' => 2, 'context_weight' => 9]),
            self::event('recovery-before-run-in', 'recovery', 'The run-in is approaching', 'With an important part of the Season ahead, the staff ask how you want to manage the work at {club}.', [
                self::choice('protect-freshness', 'Protect freshness for the run-in', 'Prioritised freshness before the Season run-in', null, null, 'recovery', 'run_in_preparation'),
                self::choice('maintain-intensity', 'Maintain the training intensity', 'Maintained training intensity before the Season run-in', null, null, 'development', 'run_in_preparation'),
            ], ['requires' => ['season_phase' => 'run_in', 'history_absent' => 'run_in_preparation'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 8]),
            self::event('team-dressing-room-reset', 'team', 'The squad needs a reset', 'After a difficult result, the dressing room at {club} has a choice about how to respond.', [
                self::choice('speak-up', 'Help set a positive tone', 'Helped the squad reset after a difficult result', null, null, 'professional', 'dressing_room_reset'),
                self::choice('listen-first', 'Listen and support quietly', 'Supported teammates quietly after a difficult result', null, null, 'recovery', 'dressing_room_reset'),
            ], ['requires' => ['history_absent' => 'dressing_room_reset', 'recent_team_result' => 'loss'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 8]),
            self::event('team-veteran-mentor', 'teammates', 'A veteran checks in', 'A veteran at {club} asks how the first months of senior football are really going.', [
                self::choice('open-up', 'Talk honestly and listen', 'Accepted a veteran teammate\'s mentorship', null, null, 'development', 'mentor_started'),
                self::choice('keep-it-private', 'Keep the conversation private', 'Kept a veteran teammate\'s offer in perspective', null, null, 'balanced', 'mentor_declined'),
            ], ['requires' => ['roles' => ['prospect', 'rotation'], 'history_absent' => 'mentor_started', 'history_absent_any' => ['mentor_declined']], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'chain_id' => 'mentorship', 'chain_stage' => 1, 'context_weight' => 9]),
            self::event('team-mentor-checkin', 'teammates', 'The advice comes back around', 'The veteran who spoke with you earlier asks what you made of the advice at {club}.', [
                self::choice('share-progress', 'Share what has changed', 'Shared progress with a veteran mentor', null, null, 'professional', 'mentor_followed'),
                self::choice('keep-learning', 'Keep learning quietly', 'Continued learning from a veteran mentor', null, null, 'development', 'mentor_followed'),
            ], ['requires' => ['history_present' => 'mentor_started', 'history_absent' => 'mentor_followed'], 'newsworthy' => false, 'repeatability' => 'once_per_career', 'chain_id' => 'mentorship', 'chain_stage' => 2, 'context_weight' => 12]),
            self::event('team-mentor-reflection', 'career', 'A lesson worth keeping', 'The season has given you enough distance to understand what the early advice at {club} meant.', [
                self::choice('record-the-lesson', 'Carry the lesson forward', 'Recorded a lasting lesson from a veteran mentor', null, null, 'professional', 'mentor_reflection'),
                self::choice('pass-it-on', 'Pass the lesson to a younger teammate', 'Passed a mentor\'s lesson on to a younger teammate', null, null, 'lifestyle', 'mentor_reflection'),
            ], ['requires' => ['history_present' => 'mentor_followed', 'history_absent' => 'mentor_reflection'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'chain_id' => 'mentorship', 'chain_stage' => 3, 'context_weight' => 13]),
            self::event('team-new-teammate', 'teammates', 'A new teammate arrives', 'A new face has joined {club}, and the first impression can shape how quickly the squad feels settled.', [
                self::choice('welcome-them', 'Make them feel welcome', 'Welcomed a new teammate to the Club', null, null, 'professional', 'new_teammate_welcome'),
                self::choice('focus-on-work', 'Let the football do the talking', 'Let the football lead after a new teammate arrived', null, null, 'development'),
            ], ['requires' => ['history_absent' => 'new_teammate_welcome'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 5]),
            self::event('family-homecoming', 'family', 'A quiet homecoming', 'A break gives you a chance to reconnect with home after the demands of football at {club}.', [
                self::choice('go-home', 'Make the trip home', 'Made time for a homecoming during the Season', null, null, 'lifestyle', 'homecoming'),
                self::choice('stay-connected', 'Stay connected from the Club', 'Stayed connected with family from the Club', null, null, 'balanced', 'homecoming'),
            ], ['requires' => ['history_absent' => 'homecoming'], 'priority_categories' => ['lifestyle', 'recovery'], 'newsworthy' => false, 'historyworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 4]),
            self::event('friends-old-town', 'friends', 'Old friends remember you', 'Friends from home are nearby and want to see the person behind the growing football routine at {club}.', [
                self::choice('make-time', 'Make time for them', 'Made time for old friends during the Season', null, null, 'lifestyle', 'old_friends'),
                self::choice('keep-it-short', 'Meet briefly before returning to work', 'Met old friends briefly while keeping the football routine', null, null, 'balanced', 'old_friends'),
            ], ['requires' => ['history_absent' => 'old_friends'], 'priority_categories' => ['lifestyle', 'balanced'], 'newsworthy' => false, 'historyworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 4]),
            self::event('social-recognition', 'social', 'A familiar face in public', 'Someone recognises you away from the ground after your recent work for {club}.', [
                self::choice('be-generous', 'Take a moment to say hello', 'Took a moment to acknowledge a supporter away from the ground', null, null, 'lifestyle', 'public_recognition'),
                self::choice('keep-moving', 'Keep the moment private', 'Kept a public moment private and returned to routine', null, null, 'recovery', 'public_recognition'),
            ], ['requires' => ['history_absent' => 'public_recognition', 'appearances_min' => 1], 'newsworthy' => false, 'historyworthy' => false, 'repeatability' => 'once_per_career', 'context_weight' => 5]),
            self::event('fan-autograph', 'fans', 'A young supporter asks for a moment', 'A young supporter recognises you and asks for an autograph after seeing you represent {club}.', [
                self::choice('stop-and-sign', 'Stop and sign', 'Stopped to sign for a young supporter', null, null, 'lifestyle', 'fan_connection'),
                self::choice('send-a-message', 'Offer a quick word and keep moving', 'Offered encouragement to a young supporter', null, null, 'professional', 'fan_connection'),
            ], ['requires' => ['appearances_min' => 1, 'history_absent' => 'fan_connection'], 'newsworthy' => false, 'repeatability' => 'once_per_career', 'context_weight' => 5]),
            self::event('media-breakout-interview', 'media', 'A local interview follows your form', 'A local reporter wants to understand the work behind your recent performances for {club}.', [
                self::choice('credit-the-team', 'Credit the team and the routine', 'Credited the team after a local interview about strong form', null, null, 'professional', 'media_breakout'),
                self::choice('keep-expectations-low', 'Keep expectations measured', 'Kept expectations measured in a local interview', null, null, 'balanced', 'media_breakout'),
            ], ['requires' => ['forms' => ['excellent', 'good'], 'history_absent' => 'media_breakout'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 10]),
            self::event('media-first-goal', 'media', 'Questions after your first goal', 'The local media want to hear what your first senior goal for {club} means to you.', [
                self::choice('share-the-credit', 'Share the credit', 'Shared the credit after a first senior goal interview', null, null, 'professional', 'first_goal_media'),
                self::choice('keep-it-personal', 'Keep the answer personal', 'Kept a first goal interview personal and measured', null, null, 'balanced', 'first_goal_media'),
            ], ['requires' => ['recent_goals_min' => 1, 'history_absent' => 'first_goal_media'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 10]),
            self::event('community-youth-visit', 'community', 'An invitation from young Players', 'A local youth group would like to hear how you found your first steps in senior football at {club}.', [
                self::choice('share-the-journey', 'Share the journey honestly', 'Shared the early career journey with young Players', null, null, 'professional', 'youth_visit'),
                self::choice('keep-it-brief', 'Make a short, focused visit', 'Made a short visit to a local youth group', null, null, 'balanced', 'youth_visit'),
            ], ['requires' => ['appearances_min' => 1, 'history_absent' => 'youth_visit'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 7]),
            self::event('adaptation-new-club', 'adaptation', 'A new Club needs a new routine', 'Your move to {club} has changed the city, the dressing room, and the rhythm of the week.', [
                self::choice('learn-the-routine', 'Learn the new routine', 'Started settling into the new Club and its routines', null, null, 'professional', 'settling_in_started'),
                self::choice('ask-for-support', 'Ask teammates for support', 'Asked the new Club for help settling in', null, null, 'lifestyle', 'settling_in_started'),
                self::choice('take-time-to-adjust', 'Give yourself time to adjust', 'Gave yourself time to adjust after moving Club', null, null, 'recovery', 'settling_in_started'),
            ], ['requires' => ['recent_transfer' => true, 'history_absent' => 'settling_in_started'], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'chain_id' => 'settling-in', 'chain_stage' => 1, 'context_weight' => 15]),
            self::event('adaptation-team-support', 'adaptation', 'The new Club feels closer', 'The people around {club} can see that you are finding your feet and offer one more way to feel part of the group.', [
                self::choice('join-the-group', 'Join the group routine', 'Found a stronger sense of belonging at the new Club', null, null, 'lifestyle', 'settling_in_complete'),
                self::choice('keep-your-anchor', 'Keep one familiar routine', 'Kept a familiar routine while settling into the new Club', null, null, 'balanced', 'settling_in_complete'),
            ], ['requires' => ['history_present' => 'settling_in_started', 'history_absent' => 'settling_in_complete'], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'chain_id' => 'settling-in', 'chain_stage' => 2, 'context_weight' => 12]),
            self::event('career-transfer-request', 'transfer', 'A transfer request changes the mood', 'Your request to leave {club} has become part of the conversation around the squad.', [
                self::choice('stay-professional', 'Stay professional until the next step', 'Stayed professional while a transfer request was active', null, null, 'professional', 'transfer_professional'),
                self::choice('speak-with-staff', 'Speak openly with the staff', 'Spoke openly with the staff while a transfer request was active', null, null, 'balanced', 'transfer_open'),
            ], ['requires' => ['transfer_request' => 'requested', 'history_absent' => 'transfer_professional'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 14]),
            self::event('contract-final-year', 'contract', 'Your contract is entering its final stretch', 'With the current Contract approaching its boundary at {club}, the football remains the clearest way to shape what comes next.', [
                self::choice('focus-on-football', 'Let the football lead', 'Focused on football while the Contract entered its final stretch', null, null, 'professional', 'contract_focus'),
                self::choice('ask-for-clarity', 'Ask for clarity about the future', 'Asked for clarity while the Contract entered its final stretch', null, null, 'balanced', 'contract_focus'),
            ], ['requires' => ['contract_expiring' => true, 'history_absent' => 'contract_focus'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 12]),
            self::event('role-prospect-patience', 'role', 'Patience is part of the pathway', 'As a Prospect at {club}, you can either keep learning or ask for a clearer route to the team.', [
                self::choice('keep-learning', 'Keep learning and wait for the chance', 'Kept learning patiently as a Prospect', null, null, 'development', 'prospect_patience'),
                self::choice('ask-for-chance', 'Ask for a clear opportunity', 'Asked for a clear opportunity as a Prospect', null, null, 'professional', 'prospect_patience'),
            ], ['requires' => ['roles' => ['prospect'], 'history_absent' => 'prospect_patience'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 10]),
            self::event('role-rotation-rival', 'role', 'A rotation place is up for grabs', 'Competition for minutes is close at {club}, and the next training block can shape the order.', [
                self::choice('compete-for-minutes', 'Compete for the place', 'Competed for minutes as a Rotation Player', null, null, 'development', 'rotation_competition'),
                self::choice('serve-the-squad', 'Serve the squad wherever needed', 'Served the squad while competing for a Rotation place', null, null, 'professional', 'rotation_competition'),
            ], ['requires' => ['roles' => ['rotation'], 'history_absent' => 'rotation_competition'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 10]),
            self::event('role-key-responsibility', 'role', 'Responsibility comes with the role', 'As a Key Player at {club}, teammates and staff are looking to you during the next stretch.', [
                self::choice('lead-by-example', 'Lead through preparation', 'Accepted extra responsibility as a Key Player', null, null, 'professional', 'key_responsibility'),
                self::choice('share-the-load', 'Share the responsibility with the squad', 'Shared responsibility with the squad as a Key Player', null, null, 'balanced', 'key_responsibility'),
            ], ['requires' => ['roles' => ['key_player'], 'history_absent' => 'key_responsibility'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 10]),
            self::event('season-promotion-pressure', 'season', 'The promotion race is real', 'The table has made the promotion pressure around {club} impossible to ignore.', [
                self::choice('embrace-pressure', 'Embrace the pressure', 'Embraced promotion pressure around the Club', null, null, 'professional', 'promotion_pressure'),
                self::choice('stay-week-to-week', 'Stay focused on the next Match', 'Stayed focused on the next Match during a promotion race', null, null, 'balanced', 'promotion_pressure'),
            ], ['requires' => ['history_absent' => 'promotion_pressure', 'competition_pressure' => 'promotion'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 8]),
            self::event('season-relegation-pressure', 'season', 'The fight at the bottom tightens', 'The table has made every point important for {club}.', [
                self::choice('support-the-group', 'Support the group', 'Supported the group during a relegation fight', null, null, 'professional', 'relegation_pressure'),
                self::choice('protect-your-routine', 'Protect your routine', 'Protected the routine during a relegation fight', null, null, 'recovery', 'relegation_pressure'),
            ], ['requires' => ['history_absent' => 'relegation_pressure', 'competition_pressure' => 'relegation'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 8]),
            self::event('free-agent-next-step', 'career', 'A free agent weighs the next step', 'Without a current Club, the next part of your career needs patience and a clear routine.', [
                self::choice('keep-training', 'Keep training on your own plan', 'Kept a professional routine as a Free Agent', null, null, 'development', 'free_agent_routine'),
                self::choice('stay-ready', 'Stay ready for the right opportunity', 'Stayed ready while considering the next opportunity', null, null, 'balanced', 'free_agent_routine'),
            ], ['requires' => ['free_agent' => true, 'history_absent' => 'free_agent_routine'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 14]),
            self::event('club-culture-welcome', 'club_culture', 'The Club shows you its way', 'People at {club} invite you into a Club tradition that helps new Players understand the place.', [
                self::choice('join-in', 'Join the tradition', 'Joined a Club tradition after arriving at the Club', null, null, 'lifestyle', 'club_culture'),
                self::choice('observe-first', 'Observe before joining in', 'Observed a Club tradition before joining in', null, null, 'balanced', 'club_culture'),
            ], ['requires' => ['history_absent' => 'club_culture', 'current_club' => true], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'context_weight' => 4]),
            self::event('lifestyle-recovery-routine', 'recovery', 'Your off-pitch routine takes shape', 'The recovery support you have built at home gives the next stretch around {club} a little more structure.', [
                self::choice('protect-the-routine', 'Protect the routine', 'Built a steadier recovery routine around the football week', null, null, 'recovery', 'lifestyle_recovery_routine'),
                self::choice('share-what-works', 'Share what works with the staff', 'Shared an off-pitch recovery routine with the staff', null, null, 'professional', 'lifestyle_recovery_routine'),
            ], ['requires' => ['owned_effect' => ['effect' => 'recovery_support', 'min' => 1], 'history_absent' => 'lifestyle_recovery_routine'], 'priority_categories' => ['recovery', 'professional'], 'newsworthy' => false, 'repeatability' => 'once_per_career', 'context_weight' => 7]),
            self::event('first-wage-perspective', 'financial', 'Your first wage changes the week', 'The first real wage from {club} makes the career feel more concrete. You can keep the money close or use a small part of it to support something beyond yourself.', [
                self::choice('keep-grounded', 'Keep the money for your next step', 'Kept the first wage focused on the next career step', 'first_wage_perspective'),
                self::choice('support-community', 'Support a local football session', 'Used part of the first wage to support a local football session', 'community_support', null, 'professional', null, ['amount' => -10, 'type' => 'event_expense', 'context' => 'Supported a local football session']),
            ], ['requires' => ['wage_income_min' => 1, 'history_absent' => 'first_wage_perspective'], 'priority_categories' => ['professional', 'lifestyle'], 'newsworthy' => false, 'context_weight' => 9]),
            self::event('lifestyle-first-home', 'lifestyle', 'A place that feels like yours', 'The first home you have chosen around {club} changes how the football week feels. You can settle into it or keep your routine deliberately simple.', [
                self::choice('make-it-home', 'Build a calm home routine', 'Made a first home feel like part of the football routine', 'first_home_routine', null, 'recovery'),
                self::choice('keep-it-simple', 'Keep the routine light and flexible', 'Kept a simple home routine while the career was still moving', 'first_home_routine', null, 'balanced'),
            ], ['requires' => ['owned_category' => 'Home', 'history_absent' => 'first_home_routine'], 'priority_categories' => ['recovery', 'lifestyle'], 'repeatability' => 'once_per_career', 'context_weight' => 11]),
            self::event('lifestyle-first-transport', 'lifestyle', 'More freedom between fixtures', 'A new way to get around {club} has made the small parts of the football week easier. The choice is how much convenience you want to build into the routine.', [
                self::choice('use-the-freedom', 'Use the freedom to protect recovery', 'Used new travel freedom to protect recovery time', 'first_transport_routine', null, 'recovery'),
                self::choice('stay-grounded', 'Keep the old routine where it works', 'Kept a grounded travel routine after a lifestyle upgrade', 'first_transport_routine', null, 'balanced'),
            ], ['requires' => ['owned_category' => 'Transport', 'history_absent' => 'first_transport_routine'], 'priority_categories' => ['recovery', 'lifestyle'], 'repeatability' => 'once_per_career', 'context_weight' => 9]),
            self::event('lifestyle-training-investment', 'training', 'The work away from the Club', 'The training support you have built around {club} gives you a choice about how seriously to structure the next development block.', [
                self::choice('share-the-plan', 'Share the plan with the staff', 'Connected an off-pitch training investment to the Club plan', 'training_investment', null, 'professional'),
                self::choice('keep-it-personal', 'Keep it as a personal routine', 'Kept an off-pitch training investment as a personal routine', 'training_investment', null, 'development'),
            ], ['requires' => ['owned_effect' => ['effect' => 'training_support', 'min' => 1], 'history_absent' => 'training_investment'], 'priority_categories' => ['development', 'professional'], 'repeatability' => 'once_per_career', 'context_weight' => 10]),
            self::event('lifestyle-community-choice', 'community', 'A chance to give something back', 'A local football project asks whether you can support one session while your career is moving forward at {club}.', [
                self::choice('make-time', 'Give time without making a show of it', 'Gave time to a local football project', 'community_time', null, 'professional'),
                self::choice('make-a-small-gift', 'Make a small practical contribution', 'Made a small contribution to a local football project', 'community_support_p2', null, 'professional', null, ['amount' => -25, 'type' => 'event_expense', 'context' => 'Supported a local football project']),
                self::choice('protect-the-football-week', 'Keep the next football block clear', 'Kept the football week clear while supporting the project in spirit', 'community_time', null, 'balanced'),
            ], ['requires' => ['balance_min' => 25, 'history_absent' => 'community_time'], 'priority_categories' => ['professional', 'lifestyle'], 'repeatability' => 'once_per_season', 'context_weight' => 6]),
            self::event('lifestyle-wealth-pressure', 'financial', 'Success changes the outside noise', 'People around {club} have noticed that your career is going well. You can enjoy the visibility, protect your routine, or keep your next move private.', [
                self::choice('enjoy-the-moment', 'Enjoy the moment with the group', 'Enjoyed a successful career moment without losing the football focus', 'wealth_pressure', null, 'lifestyle'),
                self::choice('protect-the-routine', 'Protect the routine', 'Protected the football routine as attention increased', 'wealth_pressure', null, 'professional'),
                self::choice('keep-it-private', 'Keep the next step private', 'Kept financial progress private and focused on the next step', 'wealth_pressure', null, 'balanced'),
            ], ['requires' => ['financial_context' => ['wealthy', 'elite'], 'current_club' => true, 'history_absent' => 'wealth_pressure'], 'priority_categories' => ['lifestyle', 'professional'], 'repeatability' => 'once_per_season', 'context_weight' => 13]),
            self::event('lifestyle-free-agent-caution', 'financial', 'A quieter financial week', 'Without a current Club, the balance you have built gives you room to choose patience rather than rush the next football decision.', [
                self::choice('protect-the-buffer', 'Protect the buffer', 'Protected a financial buffer while waiting for the next Club', 'free_agent_buffer', null, 'balanced'),
                self::choice('invest-in-readiness', 'Keep readiness at the centre', 'Kept the professional routine going while between Clubs', 'free_agent_buffer', null, 'development'),
            ], ['requires' => ['free_agent' => true, 'history_absent' => 'free_agent_buffer'], 'priority_categories' => ['development'], 'repeatability' => 'once_per_season', 'context_weight' => 10]),
            self::event('lifestyle-contract-buffer', 'contract', 'The next Contract is on the horizon', 'With the current Contract entering its final stretch at {club}, a large lifestyle decision would carry a different kind of weight.', [
                self::choice('wait-for-clarity', 'Wait until the Contract picture is clearer', 'Waited before making a major lifestyle commitment', 'contract_lifestyle_caution', null, 'balanced'),
                self::choice('choose-the-routine', 'Choose the routine that serves football now', 'Chose a practical routine while the Contract picture developed', 'contract_lifestyle_caution', null, 'professional'),
            ], ['requires' => ['contract_expiring' => true, 'history_absent' => 'contract_lifestyle_caution'], 'priority_categories' => ['balanced', 'professional'], 'repeatability' => 'once_per_season', 'context_weight' => 12]),
            self::event('lifestyle-relocation-home', 'adaptation', 'Making a new city workable', 'After the move to {club}, the home and travel routines you already own need to fit a different football week.', [
                self::choice('adapt-the-routine', 'Adapt the routine to the new city', 'Adapted an existing lifestyle routine after a transfer', 'relocation_routine', null, 'lifestyle'),
                self::choice('keep-football-first', 'Let the Club routine lead', 'Let the new Club routine lead after a transfer', 'relocation_routine', null, 'professional'),
            ], ['requires' => ['recent_transfer' => true, 'history_absent' => 'relocation_routine'], 'priority_categories' => ['lifestyle', 'professional'], 'repeatability' => 'once_per_club', 'context_weight' => 15]),
            self::event('lifestyle-home-gathering', 'social', 'A home base for the squad', 'A few teammates at {club} suggest using your home base for a quiet gathering before the next block.', [
                self::choice('host-quietly', 'Host a quiet evening', 'Hosted a quiet teammate gathering away from the pitch', 'home_gathering', null, 'lifestyle'),
                self::choice('meet-outside', 'Meet somewhere simple instead', 'Met teammates simply without turning home into a social hub', 'home_gathering', null, 'balanced'),
                self::choice('protect-recovery', 'Keep the home space for recovery', 'Kept the home space focused on recovery', 'home_gathering', null, 'recovery'),
            ], ['requires' => ['owned_category' => 'Home', 'history_absent' => 'home_gathering'], 'priority_categories' => ['lifestyle', 'recovery', 'balanced'], 'repeatability' => 'once_per_season', 'context_weight' => 7]),
            self::event('cup-approaching-draw', 'cup', 'A knockout week is coming', 'The next fixture gives {club} a chance to make progress in the domestic cup. The week needs a deliberate plan.', [
                self::choice('prepare-for-the-tie', 'Prepare for the tie', 'Prepared carefully for a domestic cup tie', 'cup_preparation', null, 'professional'),
                self::choice('protect-freshness', 'Protect freshness for the tie', 'Protected freshness before a domestic cup tie', 'cup_preparation', null, 'recovery'),
                self::choice('enjoy-the-occasion', 'Enjoy the occasion', 'Enjoyed the build-up to a domestic cup tie', 'cup_preparation', null, 'lifestyle'),
            ], ['requires' => ['next_competition_type' => 'domestic_cup', 'history_absent' => 'cup_preparation'], 'priority_categories' => ['professional', 'recovery', 'lifestyle'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 14]),
            self::event('cup-first-appearance', 'cup', 'Your first domestic cup appearance', 'You have made your first domestic cup appearance for {club}. It is a different kind of pressure from the league week.', [
                self::choice('learn-the-knockout-rhythm', 'Learn from the knockout rhythm', 'Reflected on a first domestic cup appearance', 'cup_first_appearance', null, 'development'),
                self::choice('share-the-moment', 'Share the moment with the group', 'Shared the moment of a first domestic cup appearance with the group', 'cup_first_appearance', null, 'lifestyle'),
            ], ['requires' => ['recent_competition_type' => 'domestic_cup', 'appearances_min' => 1, 'history_absent' => 'cup_first_appearance'], 'priority_categories' => ['development', 'lifestyle'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 16]),
            self::event('cup-advancement-week', 'cup', 'The cup run moves on', 'A win has put {club} into the next domestic cup round. The attention is welcome, but the league week still has to be handled.', [
                self::choice('keep-the-routine', 'Keep the routine steady', 'Kept the routine steady after domestic cup advancement', 'cup_advancement', null, 'professional'),
                self::choice('embrace-the-run', 'Embrace the run', 'Embraced the Club\'s domestic cup run', 'cup_advancement', null, 'lifestyle'),
                self::choice('recover-between-fixtures', 'Recover between fixtures', 'Prioritised recovery after domestic cup advancement', 'cup_advancement', null, 'recovery'),
            ], ['requires' => ['recent_competition_type' => 'domestic_cup', 'recent_team_result' => 'win', 'history_absent' => 'cup_advancement'], 'priority_categories' => ['professional', 'lifestyle', 'recovery'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 13]),
            self::event('cup-rotation-decision', 'cup', 'A chance in the knockout squad', 'The domestic cup gives the staff a choice about how to use you while the schedule at {club} stays busy.', [
                self::choice('push-for-the-start', 'Push for the start', 'Asked to take the domestic cup opportunity seriously', 'cup_role_choice', null, 'professional'),
                self::choice('be-ready-from-the-bench', 'Be ready from the bench', 'Prepared to help the Club from the domestic cup bench', 'cup_role_choice', null, 'balanced'),
                self::choice('protect-the-next-block', 'Protect the next block', 'Protected the wider football block around a domestic cup tie', 'cup_role_choice', null, 'recovery'),
            ], ['requires' => ['next_competition_type' => 'domestic_cup', 'roles' => ['prospect', 'rotation'], 'history_absent' => 'cup_role_choice'], 'priority_categories' => ['professional', 'balanced', 'recovery'], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'context_weight' => 11]),
            self::event('cup-knockout-pressure', 'cup', 'The tie carries extra weight', 'The later rounds of the domestic cup have changed the feeling around the next match for {club}.', [
                self::choice('focus-on-details', 'Focus on the details', 'Focused on the details before a high-pressure domestic cup tie', 'cup_pressure', null, 'development'),
                self::choice('help-the-team', 'Help the team settle', 'Helped the team settle before a high-pressure domestic cup tie', 'cup_pressure', null, 'professional'),
                self::choice('keep-the-moment-grounded', 'Keep the moment grounded', 'Kept a high-pressure domestic cup tie in perspective', 'cup_pressure', null, 'balanced'),
            ], ['requires' => ['next_competition_type' => 'domestic_cup', 'recent_competition_round_min' => 3, 'history_absent' => 'cup_pressure'], 'priority_categories' => ['development', 'professional', 'balanced'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 15]),
            self::event('cup-elimination-response', 'cup', 'The cup run is over', 'The domestic cup has ended for {club} this Season. There is still a long football story ahead.', [
                self::choice('reset-for-the-league', 'Reset for the league', 'Reset quickly after the domestic cup elimination', 'cup_elimination', null, 'professional'),
                self::choice('take-the-lesson', 'Take the lesson forward', 'Took a lesson from the domestic cup run', 'cup_elimination', null, 'development'),
                self::choice('give-yourself-space', 'Give yourself space', 'Gave yourself space after the domestic cup elimination', 'cup_elimination', null, 'recovery'),
            ], ['requires' => ['recent_competition_type' => 'domestic_cup', 'recent_team_result' => 'loss', 'history_absent' => 'cup_elimination'], 'priority_categories' => ['professional', 'development', 'recovery'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 12]),
            self::event('cup-late-round-focus', 'cup', 'A late-round opportunity', 'The domestic cup has reached its later stages and {club} needs everyone ready for the next demanding week.', [
                self::choice('prepare-for-the-stage', 'Prepare for the stage', 'Prepared for a late domestic cup round', 'cup_late_round', null, 'professional'),
                self::choice('keep-developing', 'Keep developing through it', 'Used a late domestic cup round as a development opportunity', 'cup_late_round', null, 'development'),
            ], ['requires' => ['next_competition_type' => 'domestic_cup', 'recent_competition_round_min' => 4, 'history_absent' => 'cup_late_round'], 'priority_categories' => ['professional', 'development'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 17]),
            self::event('europe-approaching-draw', 'europe', 'A continental week is coming', 'The next fixture takes {club} beyond the domestic calendar. The preparation feels different before a European night.', [
                self::choice('prepare-for-europe', 'Prepare for the occasion', 'Prepared carefully for a first continental fixture', 'europe_preparation', null, 'professional'),
                self::choice('protect-the-routine', 'Protect the routine', 'Protected the routine before a continental fixture', 'europe_preparation', null, 'recovery'),
                self::choice('enjoy-the-step', 'Enjoy the step up', 'Enjoyed the build-up to continental football', 'europe_preparation', null, 'lifestyle'),
            ], ['requires' => ['next_competition_type' => 'continental', 'history_absent' => 'europe_preparation'], 'priority_categories' => ['professional', 'recovery', 'lifestyle'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 18]),
            self::event('europe-debut', 'europe', 'Your European debut', 'Your first continental appearance for {club} gives the Season a wider horizon.', [
                self::choice('learn-the-level', 'Study the level carefully', 'Reflected on a first European appearance', 'europe_debut', null, 'development'),
                self::choice('share-the-night', 'Share the night with the group', 'Shared the memory of a first European appearance with the group', 'europe_debut', null, 'lifestyle'),
            ], ['requires' => ['recent_competition_type' => 'continental', 'appearances_min' => 1, 'history_absent' => 'europe_debut'], 'priority_categories' => ['development', 'lifestyle'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 21]),
            self::event('europe-group-pressure', 'europe', 'Every continental point matters', 'The group stage has become a test of concentration for {club}; the next result shapes the route ahead.', [
                self::choice('focus-on-details', 'Focus on the details', 'Focused on the details during the European group stage', 'europe_group_pressure', null, 'development'),
                self::choice('settle-the-team', 'Help settle the team', 'Helped the team stay composed in the European group stage', 'europe_group_pressure', null, 'professional'),
                self::choice('keep-perspective', 'Keep the moment in perspective', 'Kept the European group-stage pressure in perspective', 'europe_group_pressure', null, 'balanced'),
            ], ['requires' => ['next_competition_type' => 'continental', 'recent_competition_round_min' => 2, 'history_absent' => 'europe_group_pressure'], 'priority_categories' => ['development', 'professional', 'balanced'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 16]),
            self::event('europe-knockout-night', 'europe', 'A continental knockout night', 'The group stage is behind {club}; one European knockout result now changes the shape of the run.', [
                self::choice('prepare-for-the-tie', 'Prepare for the tie', 'Prepared for a European knockout tie', 'europe_knockout', null, 'professional'),
                self::choice('embrace-the-pressure', 'Embrace the pressure', 'Embraced the pressure of a European knockout tie', 'europe_knockout', null, 'lifestyle'),
            ], ['requires' => ['next_competition_type' => 'continental', 'recent_competition_round_min' => 7, 'history_absent' => 'europe_knockout'], 'priority_categories' => ['professional', 'lifestyle'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 20]),
            self::event('europe-elimination', 'europe', 'The European run is over', 'The continental run has ended for {club}, but the experience remains part of the career.', [
                self::choice('reset-domestically', 'Reset for the domestic week', 'Reset after European elimination and returned to domestic focus', 'europe_elimination', null, 'professional'),
                self::choice('take-the-lesson', 'Take the lesson forward', 'Took a lesson from European elimination', 'europe_elimination', null, 'development'),
                self::choice('make-space', 'Give yourself space', 'Gave yourself space after the European run ended', 'europe_elimination', null, 'recovery'),
            ], ['requires' => ['recent_competition_type' => 'continental', 'recent_team_result' => 'loss', 'history_absent' => 'europe_elimination'], 'priority_categories' => ['professional', 'development', 'recovery'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 19]),
            self::event('europe-final', 'europe', 'A continental final awaits', 'The final is close enough to touch now. {club} has made the largest stage of its European run.', [
                self::choice('prepare-for-history', 'Prepare for the occasion', 'Prepared for a first continental final', 'europe_final', null, 'professional'),
                self::choice('stay-grounded', 'Stay grounded in the routine', 'Stayed grounded before a continental final', 'europe_final', null, 'balanced'),
            ], ['requires' => ['next_competition_type' => 'continental', 'recent_competition_round_min' => 8, 'history_absent' => 'europe_final'], 'priority_categories' => ['professional', 'balanced'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 23]),
            self::event('europe-championship', 'europe', 'Champions of the continent', 'The European final has been won. The celebration at {club} will become one of the defining memories of the career.', [
                self::choice('celebrate-together', 'Celebrate with the group', 'Celebrated a European championship with the group', 'europe_championship', null, 'lifestyle'),
                self::choice('remember-the-work', 'Remember the work behind it', 'Remembered the work behind a European championship', 'europe_championship', null, 'professional'),
            ], ['requires' => ['recent_competition_type' => 'continental', 'recent_team_result' => 'win', 'recent_competition_round_min' => 9, 'history_absent' => 'europe_championship'], 'priority_categories' => ['lifestyle', 'professional'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 25]),
            self::event('social-breakout-spotlight', 'media', 'A wider spotlight arrives', 'A strong run for {club} has moved you from promising Player to someone the football public is beginning to recognise.', [
                self::choice('spotlight-team', 'Credit the team and the work', 'Credited the team when a wider football spotlight arrived', null, null, 'professional', null, null, ['public_profile' => 2, 'club_standing' => 2, 'supporter_score' => 3, 'manager_score' => 2, 'history' => true]),
                self::choice('spotlight-confident', 'Speak confidently about the next step', 'Spoke confidently when a wider football spotlight arrived', null, null, 'professional', null, null, ['public_profile' => 3, 'supporter_score' => 1, 'manager_score' => 1, 'history' => true]),
                self::choice('spotlight-reserved', 'Keep the attention measured', 'Kept a new football spotlight measured and focused', null, null, 'balanced', null, null, ['public_profile' => 1, 'manager_score' => 2]),
            ], ['requires' => ['public_profile_min' => 30, 'forms' => ['excellent', 'good'], 'history_absent' => 'social_breakout'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 18]),
            self::event('social-public-scrutiny', 'media', 'Questions follow the form', 'Expectations around {club} have risen, and a difficult run has brought sharper questions from the football public.', [
                self::choice('own-the-form', 'Own the responsibility', 'Accepted responsibility when public questions followed a poor run', null, null, 'professional', null, null, ['public_profile' => 1, 'supporter_score' => 2, 'manager_score' => 1, 'history' => true]),
                self::choice('protect-the-team', 'Protect the team', 'Protected the team when public questions followed a poor run', null, null, 'professional', null, null, ['supporter_score' => 3, 'manager_score' => 2]),
                self::choice('keep-it-private', 'Keep working without adding to the noise', 'Kept a difficult public conversation private and returned to work', null, null, 'balanced', null, null, ['supporter_score' => 1]),
            ], ['requires' => ['public_profile_min' => 55, 'forms' => ['poor', 'very_poor'], 'history_absent' => 'social_scrutiny'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 17]),
            self::event('supporters-rally', 'fans', 'Supporters notice the commitment', 'Supporters at {club} have noticed the work behind your recent performances and want you to know they are behind you.', [
                self::choice('acknowledge-support', 'Acknowledge the support', 'Acknowledged supporters after they rallied around the Player', null, null, 'professional', null, null, ['supporter_score' => 5, 'club_standing' => 2, 'history' => true]),
                self::choice('keep-routine', 'Let the football answer', 'Kept the routine after supporters rallied around the Player', null, null, 'balanced', null, null, ['supporter_score' => 2, 'club_standing' => 1]),
            ], ['requires' => ['club_standing_min' => 35, 'supporter_sentiment' => 'supportive', 'history_absent' => 'supporters_rally'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 12]),
            self::event('supporters-question-form', 'fans', 'Supporters want a response', 'A difficult spell has made the mood around {club} more uncertain. The next response has to come through football.', [
                self::choice('respond-with-work', 'Respond through your work', 'Responded to supporter doubts through work on the pitch', null, null, 'professional', null, null, ['supporter_score' => 5, 'manager_score' => 2]),
                self::choice('ask-for-time', 'Ask for patience', 'Asked supporters for patience during a difficult spell', null, null, 'balanced', null, null, ['supporter_score' => 2]),
            ], ['requires' => ['supporter_sentiment' => 'skeptical', 'forms' => ['poor', 'very_poor'], 'history_absent' => 'supporters_question'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 11]),
            self::event('manager-trust-review', 'manager', 'Trust brings a bigger role', 'The Club manager sees a Player who can be trusted with more responsibility at {club}.', [
                self::choice('team-first-trust', 'Keep the team first', 'Kept the team first when manager trust brought a bigger role', null, null, 'professional', null, null, ['manager_score' => 4, 'club_standing' => 2, 'history' => true]),
                self::choice('ask-for-responsibility', 'Ask how to carry the responsibility', 'Asked how to carry a bigger role after earning manager trust', null, null, 'development', null, null, ['manager_score' => 3, 'club_standing' => 1]),
            ], ['requires' => ['manager_relationship' => 'trusted', 'history_absent' => 'manager_trust_review'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 10]),
            self::event('manager-patience-repair', 'manager', 'A relationship needs rebuilding', 'A strained conversation with the manager is not permanent, but the next weeks at {club} need professionalism.', [
                self::choice('rebuild-trust', 'Rebuild trust through consistency', 'Started rebuilding manager trust through consistency', null, null, 'professional', null, null, ['manager_score' => 6, 'club_standing' => 1, 'history' => true]),
                self::choice('ask-for-targets', 'Ask for clear targets', 'Asked the manager for clear targets while rebuilding trust', null, null, 'development', null, null, ['manager_score' => 4]),
            ], ['requires' => ['manager_relationship' => 'strained', 'history_absent' => 'manager_repair'], 'newsworthy' => false, 'repeatability' => 'once_per_club', 'context_weight' => 12]),
            self::event('social-mentor-followthrough', 'teammates', 'A mentor relationship takes shape', 'The earlier conversation with a senior teammate has become a useful part of life at {club}.', [
                self::choice('apply-advice', 'Apply the advice openly', 'Applied a mentor\'s advice as the relationship took shape', null, null, 'development', null, null, ['public_profile' => 1, 'club_standing' => 2, 'relationship_type' => 'mentor', 'relationship_context' => 'A senior teammate has become a steady football mentor.', 'history' => true]),
                self::choice('pass-it-on', 'Pass the lesson on', 'Passed a mentor\'s lesson on to another teammate', null, null, 'professional', null, null, ['club_standing' => 3, 'supporter_score' => 2, 'relationship_type' => 'ally', 'relationship_context' => 'Shared football knowledge strengthened a teammate alliance.', 'history' => true]),
            ], ['requires' => ['relationship_type' => 'mentor', 'history_absent' => 'mentor_followthrough_social'], 'newsworthy' => false, 'repeatability' => 'once_per_career', 'context_weight' => 13]),
            self::event('social-position-competition', 'teammates', 'Competition for the place intensifies', 'A teammate competing for the same position has raised the standard at {club}.', [
                self::choice('professional-competitor', 'Compete professionally', 'Built a professional position competition through football', null, null, 'development', null, null, ['manager_score' => 1, 'relationship_type' => 'competitor', 'relationship_context' => 'Regular competition for the same role remains professional.', 'history' => true]),
                self::choice('share-standard', 'Raise the standard together', 'Raised the standard with a position competitor', null, null, 'professional', null, null, ['club_standing' => 2, 'relationship_type' => 'ally', 'relationship_context' => 'Position competition became a constructive alliance.']),
            ], ['requires' => ['position_competition' => true, 'history_absent' => 'position_competition_social'], 'newsworthy' => false, 'repeatability' => 'once_per_season', 'context_weight' => 12]),
            self::event('international-profile-recognition', 'media', 'Recognition reaches beyond the Club', 'Your work for Club and country has made you a more visible figure in the football world.', [
                self::choice('represent-with-care', 'Represent both teams carefully', 'Handled growing Club and country recognition with care', null, null, 'professional', null, null, ['public_profile' => 2, 'international_profile' => 1, 'history' => true]),
                self::choice('keep-football-central', 'Keep football at the centre', 'Kept football central as international recognition grew', null, null, 'balanced', null, null, ['public_profile' => 1, 'international_profile' => 1]),
            ], ['requires' => ['international_profile_min' => 20, 'public_profile_min' => 35, 'history_absent' => 'international_profile_recognition'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 16]),
            self::event('club-favorite-recognition', 'fans', 'The Club feels like home', 'Long service and important moments have made you one of the familiar faces supporters associate with {club}.', [
                self::choice('thank-the-club', 'Thank the Club community', 'Thanked the Club community after becoming a supporter favorite', null, null, 'professional', null, null, ['club_standing' => 4, 'supporter_score' => 5, 'history' => true]),
                self::choice('stay-grounded', 'Stay grounded in the work', 'Stayed grounded after becoming a supporter favorite', null, null, 'balanced', null, null, ['club_standing' => 2, 'supporter_score' => 3]),
            ], ['requires' => ['club_standing_min' => 70, 'history_absent' => 'club_favorite_recognition'], 'newsworthy' => true, 'repeatability' => 'once_per_career', 'context_weight' => 15]),
            self::event('rivalry-matchday-context', 'career', 'A familiar rival returns', 'A meaningful football rivalry is part of the context before the next important Match.', [
                self::choice('focus-on-football', 'Keep the focus on football', 'Kept a meaningful rivalry in football perspective', null, null, 'professional', null, null, ['public_profile' => 1, 'history' => true]),
                self::choice('embrace-the-history', 'Embrace the history of the meeting', 'Embraced the history of a meaningful football rivalry', null, null, 'balanced', null, null, ['public_profile' => 2, 'supporter_score' => 2, 'history' => true]),
            ], ['requires' => ['history_present' => 'rivalry_started', 'history_absent' => 'rivalry_matchday'], 'newsworthy' => true, 'repeatability' => 'once_per_season', 'context_weight' => 14]),
        ];
    }

    /** @param list<array<string, mixed>> $choices @param array<string, mixed> $requirements */
    private static function event(string $id, string $category, string $title, string $description, array $choices, array $requirements = []): array
    {
        return [
            'id' => $id,
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'choices' => $choices,
            'requirements' => $requirements['requires'] ?? [],
            'priority_categories' => $requirements['priority_categories'] ?? [],
            'newsworthy' => (bool) ($requirements['newsworthy'] ?? false),
            'historyworthy' => (bool) ($requirements['historyworthy'] ?? true),
            'repeatability' => (string) ($requirements['repeatability'] ?? 'cooldown'),
            'cooldown_days' => (int) ($requirements['cooldown_days'] ?? 90),
            'chain_id' => $requirements['chain_id'] ?? null,
            'chain_stage' => $requirements['chain_stage'] ?? null,
            'context_weight' => (int) ($requirements['context_weight'] ?? 0),
        ];
    }

    private static function choice(string $id, string $label, string $history, ?string $memory = null, ?string $focus = null, ?string $priority = null, ?string $alsoMemory = null, ?array $finance = null, ?array $social = null): array
    {
        $choice = ['id' => $id, 'label' => $label, 'history' => $history];
        if ($memory !== null) { $choice['memory'] = $memory; }
        if ($alsoMemory !== null) { $choice['memory'] = $alsoMemory; }
        if ($focus !== null) { $choice['focus'] = $focus; }
        if ($priority !== null) { $choice['priority'] = $priority; }
        if ($finance !== null) { $choice['finance'] = $finance; }
        if ($social !== null) { $choice['social'] = $social; }

        return $choice;
    }
}
