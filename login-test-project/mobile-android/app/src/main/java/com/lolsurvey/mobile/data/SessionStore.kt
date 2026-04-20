package com.lolsurvey.mobile.data

import android.content.Context

class SessionStore(context: Context) {
    private val preferences = context.getSharedPreferences("mobile_session_store", Context.MODE_PRIVATE)

    fun accessToken(): String? = preferences.getString(KEY_ACCESS_TOKEN, null)

    fun saveAccessToken(token: String) {
        preferences.edit().putString(KEY_ACCESS_TOKEN, token).apply()
    }

    fun clear() {
        preferences.edit().remove(KEY_ACCESS_TOKEN).apply()
    }

    companion object {
        private const val KEY_ACCESS_TOKEN = "access_token"
    }
}
