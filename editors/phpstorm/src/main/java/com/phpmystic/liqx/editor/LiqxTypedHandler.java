package com.phpmystic.liqx.editor;

import com.intellij.codeInsight.editorActions.TypedHandlerDelegate;
import com.intellij.openapi.editor.Document;
import com.intellij.openapi.editor.Editor;
import com.intellij.openapi.fileTypes.FileType;
import com.intellij.openapi.project.Project;
import com.intellij.psi.PsiFile;
import org.jetbrains.annotations.NotNull;

public final class LiqxTypedHandler extends TypedHandlerDelegate {

    @NotNull
    @Override
    public Result beforeCharTyped(char c, @NotNull Project project, @NotNull Editor editor, @NotNull PsiFile file, @NotNull FileType fileType) {
        if (!file.getName().endsWith(".liqx")) {
            return Result.CONTINUE;
        }

        int offset = editor.getCaretModel().getOffset();
        Document document = editor.getDocument();
        CharSequence chars = document.getCharsSequence();

        if (c == '}') {
            // Overtype ` }` or `}`
            if (offset + 1 < chars.length() && chars.charAt(offset) == ' ' && chars.charAt(offset + 1) == '}') {
                editor.getCaretModel().moveToOffset(offset + 2);
                return Result.STOP;
            }
            if (offset < chars.length() && chars.charAt(offset) == '}') {
                editor.getCaretModel().moveToOffset(offset + 1);
                return Result.STOP;
            }
        } else if (c == ']' || c == ')' || c == '"' || c == '\'') {
            if (offset < chars.length() && chars.charAt(offset) == c) {
                editor.getCaretModel().moveToOffset(offset + 1);
                return Result.STOP;
            }
        }

        return Result.CONTINUE;
    }

    @NotNull
    @Override
    public Result charTyped(char c, @NotNull Project project, @NotNull Editor editor, @NotNull PsiFile file) {
        if (!file.getName().endsWith(".liqx")) {
            return Result.CONTINUE;
        }

        int offset = editor.getCaretModel().getOffset();
        Document document = editor.getDocument();
        CharSequence chars = document.getCharsSequence();

        if (c == '{') {
            // User just typed `{`. Insert `  }` and move caret between the two spaces: `{ | }`
            if (offset <= chars.length()) {
                document.insertString(offset, "  }");
                editor.getCaretModel().moveToOffset(offset + 1);
                return Result.STOP;
            }
        } else if (c == '[') {
            if (offset <= chars.length()) {
                document.insertString(offset, "]");
                return Result.STOP;
            }
        } else if (c == '(') {
            if (offset <= chars.length()) {
                document.insertString(offset, ")");
                return Result.STOP;
            }
        }

        return Result.CONTINUE;
    }
}
