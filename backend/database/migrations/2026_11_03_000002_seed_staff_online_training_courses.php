<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $courses = [
            [
                'title' => 'Adult Safeguarding (PSEAH)',
                'category' => 'Safeguarding',
                'summary' => 'Recognise and prevent sexual exploitation, abuse and harassment, and respond to concerns using the organisation’s approved reporting channels.',
                'lessons' => [
                    ['title' => 'Understanding PSEAH', 'body' => "PSEAH means preventing sexual exploitation, abuse and harassment. It applies to how we work with community members, colleagues, partners and other people we meet through our work.\n\nA difference in authority, access to services or control over resources can create a power imbalance. Everyone has a responsibility to use their position ethically and to help maintain a safe environment."],
                    ['title' => 'Prevention and professional boundaries', 'body' => "Treat everyone with dignity and maintain professional boundaries in person and online. Never use your role, access or influence to seek sexual contact, favors or other personal benefit.\n\n- Follow the organisation’s code of conduct and PSEAH policy.\n- Take concerns about your own conduct seriously and seek guidance early.\n- Do not retaliate against anyone who raises a concern or participates in a review."],
                    ['title' => 'Responding to a concern', 'body' => "If someone shares a concern, listen calmly, take them seriously and explain that information will be shared only with people who need it to respond.\n\n- Do not investigate, confront the person accused or promise a particular outcome.\n- Record only factual information and follow the approved reporting route promptly.\n- If someone is in immediate danger, prioritize their safety and use the appropriate emergency procedure."],
                ],
                'questions' => [
                    ['prompt' => 'What does PSEAH focus on preventing?', 'options' => ['Sexual exploitation, abuse and harassment', 'Only financial misconduct', 'Only workplace disagreements', 'Only unsafe travel'], 'correct' => 0],
                    ['prompt' => 'Why can a difference in authority create safeguarding risk?', 'options' => ['It can create a power imbalance that may be misused', 'It removes the need for professional boundaries', 'It means concerns should be handled informally', 'It makes reporting unnecessary'], 'correct' => 0],
                    ['prompt' => 'How should you respond when someone shares a PSEAH concern?', 'options' => ['Listen, take it seriously and use the approved reporting route', 'Investigate the allegation yourself', 'Confront the person accused immediately', 'Promise complete secrecy and take no action'], 'correct' => 0],
                    ['prompt' => 'What should staff do if a concern is raised about their own conduct?', 'options' => ['Follow policy and seek appropriate guidance', 'Delete related records', 'Ask the person to withdraw the concern', 'Retaliate against the person who reported it'], 'correct' => 0],
                    ['prompt' => 'How should information about a concern be shared?', 'options' => ['Only with people who need it to respond, following policy', 'With the whole team', 'On personal social media', 'With anyone who asks'], 'correct' => 0],
                ],
            ],
            [
                'title' => 'Staff Safeguarding',
                'category' => 'Safeguarding',
                'summary' => 'Understand staff responsibilities for safe conduct, preventing harm and reporting safeguarding concerns in line with organisational policy.',
                'lessons' => [
                    ['title' => 'Safeguarding is everyone’s responsibility', 'body' => "Safeguarding means taking reasonable steps to prevent harm connected with our people, activities and operations. It covers children and adults who may be affected by our work, including staff and community members.\n\nEvery staff member is responsible for following the code of conduct, noticing risks and raising concerns. Safeguarding focal points and managers support the response, but responsibility is shared."],
                    ['title' => 'Safe conduct at work', 'body' => "Use your role, resources and authority only for legitimate work purposes. Treat people fairly and respectfully, maintain appropriate boundaries and follow rules for activities, communications, travel and information handling.\n\n- Consider who may be excluded or exposed to harm by an activity.\n- Follow approved procedures when working with community members or handling sensitive information.\n- Ask a manager or safeguarding focal point for guidance when a situation is unclear."],
                    ['title' => 'Raising and responding to concerns', 'body' => "Report suspected harm, unsafe conduct or policy breaches promptly through the organisation’s designated channels. You do not need to prove what happened before raising a good-faith concern.\n\n- Listen respectfully and avoid making promises about the outcome.\n- Keep notes factual and store them only as policy allows.\n- Do not investigate or share details more widely than necessary.\n- Follow the emergency process if there is an immediate safety risk."],
                ],
                'questions' => [
                    ['prompt' => 'Who is responsible for safeguarding in day-to-day work?', 'options' => ['Every staff member', 'Only the safeguarding focal point', 'Only senior managers', 'Only staff who run field activities'], 'correct' => 0],
                    ['prompt' => 'What should you do when you notice a potential safeguarding risk?', 'options' => ['Raise it through the designated channel and follow policy', 'Wait until you have proof', 'Post details in a public group', 'Assume someone else will report it'], 'correct' => 0],
                    ['prompt' => 'Which is an example of safe conduct?', 'options' => ['Maintaining professional boundaries and following approved procedures', 'Using work authority for personal favors', 'Sharing sensitive information with anyone who asks', 'Ignoring risks to avoid delaying an activity'], 'correct' => 0],
                    ['prompt' => 'Do you need to prove a concern before reporting it in good faith?', 'options' => ['No, report the concern through the approved channel', 'Yes, investigate it yourself first', 'Yes, confront the person involved first', 'No, but only if it is discussed publicly'], 'correct' => 0],
                    ['prompt' => 'What should you do if someone may be in immediate danger?', 'options' => ['Prioritize safety and use the appropriate emergency procedure', 'Wait for the next routine meeting', 'Share the details with the whole office', 'Conduct an informal investigation first'], 'correct' => 0],
                ],
            ],
            [
                'title' => 'Conflict of Interest',
                'category' => 'Ethics & Compliance',
                'summary' => 'Identify actual, potential and perceived conflicts of interest, disclose them early and protect impartial decision-making.',
                'lessons' => [
                    ['title' => 'Recognising a conflict', 'body' => "A conflict of interest can arise when personal, family, financial or other interests could affect—or appear to affect—your work decisions. A conflict does not automatically mean wrongdoing, but it must be handled transparently.\n\nExamples can include selecting a supplier connected to you, participating in a decision about a relative or using confidential information for personal benefit."],
                    ['title' => 'Disclose and step back when needed', 'body' => "Tell your manager or the designated compliance contact as soon as you identify an actual, potential or perceived conflict. Disclose it before taking part in a related decision.\n\n- Provide accurate facts and follow the organisation’s disclosure process.\n- Do not influence a decision while the conflict is being assessed.\n- Follow any direction to recuse yourself or add independent oversight."],
                    ['title' => 'Gifts, records and impartiality', 'body' => "Follow the organisation’s rules on gifts, hospitality and outside interests. Never offer or accept something intended to improperly influence a work decision.\n\nKeep required declarations and decision records complete. If you are unsure whether a relationship or offer creates a conflict, disclose it and ask for guidance rather than deciding privately."],
                ],
                'questions' => [
                    ['prompt' => 'What should you do when you identify a potential conflict of interest?', 'options' => ['Disclose it promptly through the approved process', 'Hide it unless someone asks', 'Continue deciding and disclose it later', 'Ask the interested party to decide'], 'correct' => 0],
                    ['prompt' => 'Does a conflict of interest always mean misconduct has occurred?', 'options' => ['No, but it must be disclosed and managed', 'Yes, in every case', 'No, so it never needs to be disclosed', 'Only when money changed hands'], 'correct' => 0],
                    ['prompt' => 'When should a conflict be disclosed?', 'options' => ['Before taking part in a related decision', 'After the decision is final', 'Only at the end of the year', 'Only if the decision is challenged'], 'correct' => 0],
                    ['prompt' => 'A supplier offers you a personal gift during a procurement. What is the best response?', 'options' => ['Follow the organisation’s gifts policy and disclose the offer', 'Accept it and keep it private', 'Promise to select the supplier', 'Ask the supplier to send it to your home'], 'correct' => 0],
                    ['prompt' => 'What should you do if you are unsure whether an interest is relevant?', 'options' => ['Disclose it and ask the designated contact for guidance', 'Make the decision without telling anyone', 'Delete related communications', 'Ask the interested person to approve it'], 'correct' => 0],
                ],
            ],
            [
                'title' => 'Anti-Corruption and Anti-Money Laundering',
                'category' => 'Ethics & Compliance',
                'summary' => 'Recognise corruption and money-laundering risks, maintain reliable records and escalate concerns through approved finance and compliance channels.',
                'lessons' => [
                    ['title' => 'Recognising corruption risks', 'body' => "Corruption includes abusing entrusted power for private or improper benefit. Warning signs may include requests for unofficial payments, undisclosed relationships in a procurement or pressure to bypass required approvals.\n\nDo not offer, promise, request or accept an improper benefit. Follow the organisation’s controls for procurement, cash handling, approvals and due diligence."],
                    ['title' => 'Understanding money-laundering risk', 'body' => "Money laundering can involve disguising the origin or ownership of funds connected to crime. Staff should follow approved checks and payment controls, and pay attention to transactions that do not match the documented purpose or usual process.\n\n- Use approved counterparties and payment methods.\n- Keep invoices, receipts and approvals complete and accurate.\n- Never split transactions, change descriptions or bypass checks to avoid review."],
                    ['title' => 'Records and escalation', 'body' => "Raise suspicious requests or transactions with the designated finance, compliance or management contact, following the organisation’s procedures. You are not expected to determine whether a crime occurred.\n\nPreserve relevant records, limit discussion to people who need to respond and do not alert or confront another party if policy restricts disclosure. Follow the instructions of the designated contact."],
                ],
                'questions' => [
                    ['prompt' => 'What is the appropriate response to a request for an unofficial payment?', 'options' => ['Do not make the payment and report it through the approved channel', 'Pay it and omit it from the records', 'Record it as a different expense', 'Ask a colleague to pay it instead'], 'correct' => 0],
                    ['prompt' => 'Why must invoices and payment records be accurate?', 'options' => ['They support accountability and the organisation’s financial controls', 'They are optional if the payment was small', 'They replace the need for approvals', 'They should be changed to match a preferred outcome'], 'correct' => 0],
                    ['prompt' => 'A transaction appears inconsistent with its documented purpose. What should you do?', 'options' => ['Pause as required by procedure and contact the designated finance or compliance contact', 'Change the description to make it appear routine', 'Split it into smaller payments', 'Ignore it if the requester is senior'], 'correct' => 0],
                    ['prompt' => 'Are staff expected to investigate whether suspicious activity is a crime?', 'options' => ['No, raise the concern and follow the designated process', 'Yes, interview everyone involved', 'Yes, search personal accounts', 'No, so the concern should not be reported'], 'correct' => 0],
                    ['prompt' => 'Who should receive a report about a suspicious payment?', 'options' => ['The designated finance, compliance or management contact', 'A public group chat', 'The external party involved', 'Anyone in the organisation'], 'correct' => 0],
                ],
            ],
            [
                'title' => 'Anti-Fraud',
                'category' => 'Ethics & Compliance',
                'summary' => 'Recognise common fraud indicators, protect records and report suspected fraud without conducting an unauthorized investigation.',
                'lessons' => [
                    ['title' => 'What fraud can look like', 'body' => "Fraud is intentional deception for an improper benefit or to cause a loss. Warning signs may include duplicate or altered invoices, false attendance or expense claims, unexplained inventory differences or requests to bypass controls.\n\nAn unusual item is not proof of fraud. Treat it as a concern to be reviewed through the proper process."],
                    ['title' => 'Preventing and detecting fraud', 'body' => "Follow required approvals and keep source documents complete. Use only your own system account and never sign or approve something you have not checked.\n\n- Separate duties where procedures require it.\n- Reconcile records and report unexplained differences.\n- Protect passwords, documents, equipment and funds.\n- Do not alter, backdate or destroy records to conceal an error or concern."],
                    ['title' => 'Reporting a concern', 'body' => "Report suspected fraud through the organisation’s designated reporting channel. Include what you observed, when it happened and which records may be relevant, without making assumptions beyond the facts.\n\nDo not confront the person involved or conduct your own investigation. Preserve relevant records, keep the matter confidential and follow instructions from the authorized reviewer."],
                ],
                'questions' => [
                    ['prompt' => 'You notice a duplicate invoice that may have been submitted for payment. What should you do?', 'options' => ['Preserve the records and report the concern through the approved channel', 'Delete one copy and say nothing', 'Accuse the supplier publicly', 'Approve it to avoid delaying payment'], 'correct' => 0],
                    ['prompt' => 'Does an unusual transaction prove fraud?', 'options' => ['No, it is an indicator that should be reviewed', 'Yes, in every case', 'Yes, unless the person is senior', 'No, so it should never be reported'], 'correct' => 0],
                    ['prompt' => 'What should you do with a record related to a fraud concern?', 'options' => ['Keep it intact and handle it according to policy', 'Alter it to remove confusing details', 'Delete it after making a personal copy', 'Share it widely for opinions'], 'correct' => 0],
                    ['prompt' => 'Should you investigate or confront a colleague you suspect?', 'options' => ['No, report the facts and let the authorized reviewer handle it', 'Yes, question them in front of the team', 'Yes, search their personal belongings', 'No, and do not tell anyone'], 'correct' => 0],
                    ['prompt' => 'What information is most useful when reporting a concern?', 'options' => ['Factual observations, dates and relevant records', 'Rumors and personal conclusions', 'A public accusation', 'Only the name of the person you suspect'], 'correct' => 0],
                ],
            ],
            [
                'title' => 'GEDSI Policy',
                'category' => 'Inclusion',
                'summary' => 'Apply gender equality, disability and social inclusion principles to everyday decisions, communications and activities.',
                'lessons' => [
                    ['title' => 'Understanding GEDSI', 'body' => "GEDSI stands for Gender Equality, Disability and Social Inclusion. It encourages us to notice and reduce barriers that prevent people from participating safely and meaningfully.\n\nPeople may face different or overlapping barriers because of gender, disability, age, language, location, economic circumstances or other aspects of identity and context. Ask what support is needed rather than making assumptions."],
                    ['title' => 'Inclusive activities and communication', 'body' => "Plan activities so people can access information, participate and raise concerns. Consider accessibility, timing, language, privacy, safety and whether the venue or format creates avoidable barriers.\n\n- Use respectful language and address people as they prefer.\n- Offer reasonable adjustments through the approved process.\n- Seek input from affected people without pressuring them to disclose personal information.\n- Do not tolerate discrimination, harassment or retaliation."],
                    ['title' => 'Responding to barriers or exclusion', 'body' => "Listen when someone describes a barrier or exclusion. Take the concern seriously, protect privacy and use the relevant feedback, safeguarding or management channel.\n\nRecord only necessary facts, agree next steps where appropriate and follow up through the responsible process. Do not promise an accommodation or outcome that you are not authorized to provide."],
                ],
                'questions' => [
                    ['prompt' => 'What is the purpose of GEDSI in everyday work?', 'options' => ['Identify and reduce barriers to safe, meaningful participation', 'Treat accessibility as optional', 'Make assumptions about what each person needs', 'Limit participation to the easiest group to reach'], 'correct' => 0],
                    ['prompt' => 'What is a good way to understand an individual’s access needs?', 'options' => ['Ask respectfully and avoid making assumptions', 'Ask others to disclose private information for them', 'Assume everyone needs the same adjustment', 'Ignore the request unless it is repeated'], 'correct' => 0],
                    ['prompt' => 'What should you consider when planning an activity?', 'options' => ['Access, safety, timing, language and participation barriers', 'Only the venue cost', 'Only the needs of the largest group', 'Whether feedback can be avoided'], 'correct' => 0],
                    ['prompt' => 'How should a concern about discrimination be handled?', 'options' => ['Take it seriously and use the appropriate reporting channel', 'Dismiss it if no one else witnessed it', 'Share it publicly without consent', 'Retaliate against the person who raised it'], 'correct' => 0],
                    ['prompt' => 'How should personal information about access needs be handled?', 'options' => ['Respect privacy and share only as needed through approved processes', 'Share it with the whole team', 'Use it to make assumptions about the person', 'Keep informal copies on personal devices'], 'correct' => 0],
                ],
            ],
            [
                'title' => 'Environmental Safeguarding Policy',
                'category' => 'Environmental Safeguarding',
                'summary' => 'Recognise and reduce environmental risks from work activities, and report incidents in line with organisational procedures.',
                'lessons' => [
                    ['title' => 'Environmental safeguarding basics', 'body' => "Environmental safeguarding means identifying and preventing harm to the environment that may result from our activities. Risks can include pollution, unsafe waste disposal, damage to habitats or inefficient use of natural resources.\n\nConsider the setting and possible effects before an activity begins. Follow the organisation’s environmental requirements and any applicable site procedures."],
                    ['title' => 'Preventing environmental harm', 'body' => "Use approved materials, equipment and disposal methods. Avoid unnecessary damage to land, water, plants and wildlife, and keep hazardous substances secure and handled only by authorized people.\n\n- Use the required risk assessment and approval process.\n- Keep waste, fuel and chemicals away from drains and water sources.\n- Do not burn, dump or bury waste unless an approved procedure explicitly allows it.\n- Stop and seek guidance when planned work presents an unmanaged risk."],
                    ['title' => 'Responding to an environmental incident', 'body' => "Report spills, releases, damage or other environmental incidents promptly through the organisation’s designated route. If it is safe and you are trained and authorized, follow the approved immediate response procedure.\n\nDo not put yourself or others at risk, conceal an incident or clean up hazardous materials without authorization. Record factual details and follow instructions for containment, notification and follow-up."],
                ],
                'questions' => [
                    ['prompt' => 'When should environmental risks be considered?', 'options' => ['Before and during activities, using the required assessment process', 'Only after damage occurs', 'Only for large construction projects', 'Only when a community member complains'], 'correct' => 0],
                    ['prompt' => 'What should you do with hazardous materials?', 'options' => ['Follow approved handling and disposal procedures', 'Dispose of them in the nearest drain', 'Leave them unsecured for later', 'Use them without checking the activity procedure'], 'correct' => 0],
                    ['prompt' => 'You discover a spill. What should you do?', 'options' => ['Report it promptly and follow the approved response procedure', 'Hide it if it seems small', 'Clean it up even if you are untrained', 'Wait until the next routine report'], 'correct' => 0],
                    ['prompt' => 'When should work stop for guidance?', 'options' => ['When an environmental risk is unmanaged or the procedure requires it', 'Only after the activity is complete', 'Never, if the schedule is tight', 'Only when a manager is already present'], 'correct' => 0],
                    ['prompt' => 'What should an incident report contain?', 'options' => ['Factual details handled through the designated process', 'Speculation about who is to blame', 'Only information shared publicly', 'No details until the cause is confirmed'], 'correct' => 0],
                ],
            ],
        ];

        foreach ($courses as $index => $course) {
            $courseId = DB::table('training_courses')->insertGetId([
                'title' => $course['title'],
                'category' => $course['category'],
                'summary' => $course['summary'],
                'estimated_minutes' => 20,
                'pass_mark' => 80,
                'due_date' => null,
                'is_active' => true,
                'sort_order' => $index + 1,
                'assign_all_staff' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($course['lessons'] as $lessonIndex => $lesson) {
                DB::table('training_lessons')->insert([
                    'training_course_id' => $courseId,
                    'title' => $lesson['title'],
                    'body' => $lesson['body'],
                    'sort_order' => $lessonIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($course['questions'] as $questionIndex => $question) {
                $options = $question['options'];
                $correctOption = ($index + $questionIndex) % count($options);
                $correctAnswer = $options[$question['correct']];
                array_splice($options, $question['correct'], 1);
                // Vary answer positions across seeded quizzes without changing their meaning.
                array_splice($options, $correctOption, 0, [$correctAnswer]);

                DB::table('training_questions')->insert([
                    'training_course_id' => $courseId,
                    'prompt' => $question['prompt'],
                    'options' => json_encode($options, JSON_THROW_ON_ERROR),
                    'correct_option' => $correctOption,
                    'sort_order' => $questionIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep course content and staff learning records when rolling back this data migration.
    }
};
