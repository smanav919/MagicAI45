<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Team\Team;
use App\Models\Team\TeamMember;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\Billable;
// use Laravel\Sanctum\HasApiTokens;
use Laravel\Cashier\Subscription as Subscriptions;
use App\Models\TwoCheckoutSubscriptions;
use Laravel\Passport\HasApiTokens;
use Stripe\Plan;
use App\Models\Setting;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, Billable;

    protected $fillable = [
        'team_id',
        'team_manager_id',
        'name',
        'surname',
        'email',
        'password',
        'affiliate_id',
        'affiliate_code',
        'remaining_words',
        'remaining_images',
        'email_confirmation_code',
        'email_confirmed',
        'password_reset_code',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->type == 'admin';
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($user) {
            Setting::query()->increment('user_count');
        });
    }
  
   public function isUser(): bool
    {
        return $this->type == 'user';
    }

    public function teamManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_manager_id', 'id');
    }

    public function teamMember(): HasOne
    {
        return $this->hasOne(TeamMember::class, 'user_id', 'id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    public function myCreatedTeam()
    {
        return $this->hasOne(Team::class, 'user_id', 'id');
    }

    public function relationPlan()
    {
        return $this->hasOneThrough(
            PaymentPlans::class,
            Subscriptions::class,
            'user_id',
            'id',
            'id',
            'plan_id'
        );
    }

    public function getRemainingWordsAttribute($value)
    {
        if($this->type == 'admin')
        {
            return $value;
        }

        if ($this->team_id == null) {
            return $value;
        }

        $teamMember = $this->teamMember;

        if (! $teamMember) {
            return $value;
        }

        if ($teamMember?->allow_unlimited_credits) {
            return $this->teamManager->remaining_words;
        } else {
            return $this->teamMember->remaining_words;
        }

        return $value;
    }


    public function getRemainingImagesAttribute($value)
    {
        if($this->type == 'admin')
        {
            return $value;
        }

        if ($this->team_id == null) {
            return $value;
        }

        $teamMember = $this->teamMember;

        if (! $teamMember) {
            return $value;
        }

        if ($teamMember?->allow_unlimited_credits) {
            return $this->teamManager->remaining_images;
        } else {
            return $this->teamMember->remaining_images;
        }

        return $value;
    }



    public function fullName()
    {
        return $this->name . ' ' . $this->surname;
    }

    public function email()
    {
        return $this->email;
    }

    public function openai()
    {
        return $this->hasMany(UserOpenai::class);
    }

    public function orders()
    {
        return $this->hasMany(UserOrder::class)->orderBy('created_at', 'desc');
    }

    public function plan()
    {
        return $this->hasMany(UserOrder::class)
            ->where('type', 'subscription')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function activePlan()
    {
        // $activeSub = $this->subscriptions()->where('stripe_status', 'active')->orWhere('stripe_status', 'trialing')->first();
        // $userId=Auth::user()->id;
        $userId = $this->id;
        // Get current active subscription
        $activeSub = getCurrentActiveSubscription($userId); 
        if ($activeSub != null) {
            $plan = PaymentPlans::where('id', $activeSub->plan_id)->first();
            if ($plan == null) {
                return null;
            }
            $difference = $activeSub->updated_at->diffInDays(Carbon::now());
            if ($plan->frequency == 'monthly') {
                if ($difference < 31) {
                    return $plan;
                }
            } elseif ($plan->frequency == 'yearly') {
                if ($difference < 365) {
                    return $plan;
                }
            }
        } else {
            $activeSub = getCurrentActiveSubscriptionYokkasa($userId);
            if ($activeSub != null) {
                $plan = PaymentPlans::where('id', $activeSub->plan_id)->first();
                if ($plan == null) {
                    return null;
                }
                $difference = $activeSub->updated_at->diffInDays(Carbon::now());
                if ($plan->frequency == 'monthly') {
                    if ($difference < 31) {
                        return $plan;
                    }
                } elseif ($plan->frequency == 'yearly') {
                    if ($difference < 365) {
                        return $plan;
                    }
                }
            } else {
                return null;
            }
        }
    }


    //Support Requests
    public function supportRequests()
    {
        return $this->hasMany(UserSupport::class);
    }

    //Favorites
    public function favoriteOpenai()
    {
        return $this->belongsToMany(OpenAIGenerator::class, 'user_favorites', 'user_id', 'openai_id');
    }

    //Affiliate
    public function affiliates()
    {
        return $this->hasMany(User::class, 'affiliate_id', 'id');
    }

    public function affiliateOf()
    {
        return $this->belongsTo(User::class, 'affiliate_id', 'id');
    }

    public function withdrawals()
    {
        return $this->hasMany(UserAffiliate::class);
    }

    //Chat
    public function openaiChat()
    {
        return $this->hasMany(UserOpenaiChat::class);
    }

    //Avatar
    public function getAvatar()
    {
        if ($this->avatar == null) {
            return '<span class="avatar">' . Str::upper(substr($this->name, 0, 1)) . Str::upper(substr($this->surname, 0, 1)) . '</span>';
        } else {
            $avatar = $this->avatar;
            if (strpos($avatar, 'http') === false || strpos($avatar, 'https') === false) {
                $avatar = '/' . $avatar;
            }
            return  ' <span class="avatar" style="background-image: url(' . $avatar . ')"></span>';
        }
    }

    public function couponsUsed()
    {
        return $this->belongsToMany(Coupon::class, 'coupon_users')
                    ->withTimestamps();
    }


    public function twitterSettings()
    {
        if (class_exists(TwitterSettings::class)) {
            return $this->hasMany(TwitterSettings::class);
        }
        return null; 
    }

    public function linkedinSettings()
    {
        if (class_exists(LinkedinTokens::class)) {
            return $this->hasMany(LinkedinTokens::class);
        }
        return null; 
    }

    public function scheduledPosts()
    {
        if (class_exists(ScheduledPosts::class)) {
            return $this->hasMany(ScheduledPosts::class);
        }
        return null; 
    }

    public function folders() {
        return $this->hasMany(Folders::class, 'created_by');
    }
}
