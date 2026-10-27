<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Online courses every staff member takes: lessons in order, then a quiz, then a certificate.
        Schema::create('training_courses', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->string('category', 80)->nullable();
            $table->text('summary');
            $table->unsignedSmallInteger('estimated_minutes')->default(20);
            $table->unsignedTinyInteger('pass_mark')->default(80); // percent
            $table->date('due_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('training_lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('body'); // paragraphs separated by blank lines; lines starting "- " are bullet points
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('training_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->json('options');
            $table->unsignedTinyInteger('correct_option'); // never sent to the browser
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('training_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->json('completed_lessons')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->unsignedTinyInteger('best_score')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->string('certificate_code', 20)->nullable()->unique();
            $table->timestamps();
            $table->unique(['user_id', 'training_course_id']);
        });

        // Every quiz submission, kept for HR's records.
        Schema::create('training_quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->unsignedTinyInteger('score');
            $table->boolean('passed');
            $table->timestamps();
        });

        $this->seedChildSafeguarding();
    }

    /**
     * The course the portal already listed. The material is general safeguarding
     * guidance and should be reviewed by HR against the organisation's own policy.
     */
    private function seedChildSafeguarding(): void
    {
        $now = now();
        $courseId = DB::table('training_courses')->insertGetId([
            'title' => 'Child Safeguarding', 'category' => 'Safeguarding',
            'summary' => 'What safeguarding means, how to recognise when a child may be at risk, and what to do if you have a concern.',
            'estimated_minutes' => 20, 'pass_mark' => 80, 'due_date' => null, 'is_active' => true, 'sort_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $lessons = [
            ['What safeguarding means', "Safeguarding means protecting children from abuse, neglect and exploitation, and making sure our work never puts a child at risk.\n\nIt applies to every member of staff, volunteer and partner, in every activity, location and communication — including online.\n\n- Every child has an equal right to protection, whatever their background or ability.\n- The child's safety and wellbeing always come first.\n- Safeguarding is everyone's responsibility, not only the safeguarding focal point's."],
            ['Recognising signs of harm', "Harm can be physical, emotional or sexual, or it can be neglect. A child may not tell anyone directly, so watch for changes over time.\n\n- Unexplained injuries, or explanations that don't match the injury.\n- Sudden changes in behaviour, such as becoming withdrawn, anxious or aggressive.\n- Poor hygiene, hunger or missing school regularly.\n- Fear of a particular person, or reluctance to go home.\n\nOne sign on its own does not prove abuse, but you should never ignore it."],
            ['Responding to a concern', "If a child tells you something worrying, stay calm and listen. Let them speak in their own words.\n\n- Reassure them that they did the right thing by telling you.\n- Do not promise to keep it secret — explain that you must tell someone who can help.\n- Do not investigate or ask leading questions.\n- Write down what was said, in the child's words, with the date and time, as soon as possible.\n\nReport the concern to the safeguarding focal point the same day. If a child is in immediate danger, contact the emergency services first."],
            ['Safe behaviour and boundaries', "Our behaviour must keep children safe and protect staff from misunderstanding.\n\n- Avoid being alone with a child out of sight of other adults whenever possible.\n- Never contact children through personal phones or social media accounts.\n- Only take or share photos of children with consent, and never in a way that identifies where they live.\n- Never use physical punishment or humiliating language.\n\nIf you see a colleague behaving in a way that could put a child at risk, report it in the same way as any other concern."],
        ];
        foreach ($lessons as $index => [$title, $body]) {
            DB::table('training_lessons')->insert(['training_course_id' => $courseId, 'title' => $title, 'body' => $body, 'sort_order' => $index, 'created_at' => $now, 'updated_at' => $now]);
        }

        $questions = [
            ['Who is responsible for safeguarding children?', ['Only the safeguarding focal point', 'Only managers', 'Everyone who works with or for the organisation', 'Only staff who work directly with children'], 2],
            ['A child tells you about abuse and asks you to keep it a secret. What should you do?', ['Promise to keep the secret so they keep trusting you', 'Explain kindly that you must tell someone who can help', 'Ask detailed questions to find out exactly what happened', 'Wait to see if they mention it again'], 1],
            ['When should you report a safeguarding concern?', ['The same day, to the safeguarding focal point', 'At the next team meeting', 'Only once you have proof', 'Only if the child asks you to'], 0],
            ['Which of these is acceptable?', ['Messaging a child from your personal social media account', 'Sharing a photo of a child with their home village named', 'Running an activity where other adults can see you', 'Using physical punishment for serious misbehaviour'], 2],
            ['A child is in immediate danger. What do you do first?', ['Email your line manager', 'Contact the emergency services', 'Write a report for the next day', 'Speak to the child’s parents first'], 1],
        ];
        foreach ($questions as $index => [$prompt, $options, $correct]) {
            DB::table('training_questions')->insert(['training_course_id' => $courseId, 'prompt' => $prompt, 'options' => json_encode($options), 'correct_option' => $correct, 'sort_order' => $index, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_quiz_attempts');
        Schema::dropIfExists('training_progress');
        Schema::dropIfExists('training_questions');
        Schema::dropIfExists('training_lessons');
        Schema::dropIfExists('training_courses');
    }
};
